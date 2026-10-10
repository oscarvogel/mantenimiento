# Bitácora 2026-10-10 — Verificación de `telematia_muestras` contra MySQL real

Estado: verificada en MySQL 8.4 local. Pendiente repetir sobre MariaDB 11 de
staging.

Rama: `feat/telemetria-muestras` (desde `main` en `3f575ec3`).

---

## 1. Qué se hizo

La migración `2026-10-10-160000_CreateTelemetrySamples` se verificó por
integridad contra un servidor MySQL de verdad, no sólo contra el SQLite en
memoria que usa la suite PHPUnit. Se comprobó:

1. La cadena **completa** de migraciones corre desde cero.
2. El `CREATE TABLE` real que genera CodeIgniter.
3. La deduplicación contra MySQL de verdad.
4. El `down()` de toda la cadena.

Commit: `1b2b1d1b feat(telemetria): tabla de muestras para el histórico de telemetría`.

## 2. El obstáculo: el `.env` no se puede pisar desde afuera

Lo primero que se intentó fue correr `spark` con variables de entorno:

```bash
env 'database.default.database=scratch_muestras' \
    'database.default.username=muestras' \
    php spark migrate:status
```

Seguiu conectando a la base del `.env`, con *Access denied* del usuario viejo.

**La causa.** `env()` de CI4 resuelve en este orden:

```
$_ENV[$name]  ->  $_SERVER[$name]  ->  getenv($name)
```

`$_ENV` gana. Y `DotEnv::setVariable()` hace:

```php
if (getenv($name, true) === false) putenv("{$name}={$value}");
if (empty($_ENV[$name]))         $_ENV[$name] = $value;   // <- este gana
if (empty($_SERVER[$name]))      $_SERVER[$name] = $value;
```

El `putenv` respeta el proceso, pero el `$_ENV` se llena igual desde el `.env`, y
`$_ENV` es el que se lee primero.

**El arreglo obvio no funciona.** `php -d variables_order=EGPCS` **no** lo
resuelve: PHP interpola las superglobales al arrancar, antes de aplicar el
override de `-d`. Comprobado directamente:

```
variables_order = EGPCS
getenv  = 'muestras'
$_ENV    = NULL
$_SERVER = NULL
```

**Tampoco sirve `spark --env`**, ni sobreescribir `CI_ENVIRONMENT`:
`Boot::defineEnvironment()` lee `$_ENV['CI_ENVIRONMENT']` después de que DotEnv
ya lo llenó con `production`.

**La única vía que queda** es reescribir sólo la clave `database` del `.env` para
la corrida, con backup, `trap` de restauración y comparación de md5 antes y
después. Es lo que hacen los helpers de este proyecto (`scripts/migrate.php`,
`migrate_once.php`), que leen el `.env` a mano y no pasan por DotEnv.

## 3. Base de verificación

```sql
CREATE DATABASE scratch_muestras CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'muestras'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON scratch_muestras.* TO 'muestras'@'localhost';
```

El permiso acotado a una base es lo que hace segura la verificación: aunque algo
saliera mal, no se alcanza ninguna otra base del servidor.

## 4. Resultado

**Migraciones desde cero: correctas.** Las 60 corren y `CreateTelemetrySamples`
queda registrada con batch `1`.

**El DDL real es el esperado:**

```sql
CREATE TABLE `telematia_muestras` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` int unsigned NOT NULL,
  `integracion_id` bigint unsigned NOT NULL,
  `equipo_id` int unsigned NOT NULL,
  `unidad_externa` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `observada_en` datetime NOT NULL,
  `registrada_en` datetime DEFAULT NULL,
  ...
  `combustible_litros` decimal(8,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telemetria_muestras_captura` (`integracion_id`,`unidad_externa`,`observada_en`),
  KEY `idx_telemetria_muestras_equipo` (`empresa_id`,`equipo_id`,`observada_en`),
  KEY `idx_telemetria_muestras_retencion` (`empresa_id`,`observada_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

El riesgo que había era el tamaño de la clave única: `unidad_externa` es
`varchar(100)` en `utf8mb4`, o sea 400 bytes, contra el tope de 3072 de InnoDB
con row format `Dynamic`. MySQL la aceptó sin quejarse.

**La deduplicación funciona, y por la columna correcta.** El caso que importa:

```
1er insert:  observada_en 06:15:00 / registrada_en 06:15:04   -> ok
2do insert:  observada_en 06:15:00 / registrada_en 06:15:30   -> ERROR 1062
```

El segundo insert tiene **distinto `registrada_en`** y aun así fue rechazado,
porque la clave usa `observada_en`. Si la clave hubiera usado `registrada_en`,
un reintento del cron habría creado una muestra duplicada con cada corrida.

Y los otros dos casos:

| Caso | Resultado |
|---|---|
| Misma unidad, 15 min después | entra (la cadencia se apila) |
| Otra unidad, misma hora | entra (3 filas en total) |

**`down()` verificado.** `migrate:rollback --all` baja las 60 migraciones
limpias y queda sólo la tabla de migraciones. `telematia_muestras` desaparece.

## 5. El hallazgo que cambia la Tarea 3

`INSERT IGNORE` **existe en MySQL y no en SQLite**.

```
INSERT IGNORE INTO telematia_muestras ... (duplicado)
  -> sin error, sin duplicar, affectedRows = 0
```

En SQLite el mismo `INSERT` revienta con excepción de clave única. Un almacén
deduplicado con `INSERT IGNORE` y probado en SQLite **funciona en las pruebas y
falla en producción**, o al revés: si se programó para SQLite con try/catch,
contra MySQL la excepción nunca llega y hay que leer `affectedRows` para saber
si realmente se insertó.

La prueba de SQLite no alcanza para decidir esto. La semántica de upsert se tiene
que probar en el motor verdadero.

## 6. Estado

- `scratch_muestras` queda creada y **vacía** (rollback completo), lista para
  la Tarea 2 en adelante.
- El `.env` quedó **byte a byte idéntico** al original, verificado por md5 antes
  y después de cada corrida.

## 7. Pendientes

- [ ] **Repetir la verificación sobre MariaDB 11 de staging** (`fasa_189`).
      MySQL 8.4 es compatible en todo lo que usa esta tabla, pero no es el
      motor de producción.
- [ ] **Tarea 2:** VO `MuestraEquipo` y puertos.
- [ ] **Tarea 3:** `CodeIgniterMuestraStore` con `INSERT IGNORE`, leyendo
      `affectedRows` para distinguir insertado de ignorado.
- [ ] **Tarea 4:** cablear la ingesta en `RecordTelemetrySnapshots`.

## 8. Nota operativa

El `.env` de este proyecto tiene finales de línea **CRLF**. No afecta a CI4,
que lo parsea bien, pero cualquier script que haga `grep` + `cut` sobre `.env`
se lleva el `\r` adentro del valor y termina con `Unknown MySQL server host
'localhost\r'`. Pasó durante esta verificación.