# Bitácora — importación del historial de Viajes del ERP de transporte

**Fecha:** 2026-10-17
**Rama:** `feat/viajes-import-historial`
**Base:** `codex/importacion-clientes` (commit `b157ebaa`)
**Estado:** análisis verificado + herramienta de lectura y conversión. **El importador
operativo de Viajes todavía NO está implementado y ningún dato fue importado.**

---

## 1. Resumen

Se investigaron los cuatro backups `transporte_*_pawluk-20261006.backup` y se construyó
la forma de leerlos sin PostgreSQL, sin `pg_restore` y sin CLI, porque producción
Ferozo no tiene shell. La herramienta quedó probada contra los cuatro archivos reales.

**Lo que no se hizo todavía:** el modelo de dominio de Viajes, sus migraciones, el
flujo de Superadministrador con vista previa y el confirmador transaccional. Quedan
pendientes de dos definiciones humanas, illuminate en §7.

---

## 2. Formato real de los backups

El análisis previo del repo (`ANALISIS_BACKUP_TRANSPORTE_MARIELA_Y_MIGRACION.md`) decía
`pg_dump -Ft` (tar). Confirmado, y con un detalle que cambia la estrategia de lectura:

```
transporte_mariela_pawluk-20261006.backup
└── TAR
    ├── restore.sql      DDL, PKs y el índice COPY autoritativo
    ├── toc.dat          metadatos serializados (no hace falta para leer datos)
    ├── 2491.dat … 2599.dat   un bloque COPY texto por tabla
```

- Motor de origen: **PostgreSQL 9.2.3**, encoding UTF8, base `transporte_<persona>_pawluk`.
- `mariela` y `mario`: **109 tablas**. `ariel` y `gisela`: **103 tablas**. Un `.dat` por tabla.
- **0 claves foráneas** en los cuatro. Sí hay PKs: 41 en ariel/gisela, 47 en mario, 48 en mariela.

### 2.1 El mapeo tabla → archivo NO es posicional

El orden de los `.dat` en el TAR **no coincide** con el orden del DDL. En `ariel`, el
miembro `2533.dat` aparece entre `2453.dat` y `2454.dat`; ordenar por número y emparejar
con el orden de `CREATE TABLE` asigna `2533.dat` a la tabla equivocada (comprobado: solo
11 de 20 tablas no vacías con el ancho correcto, frente a **42 de 42** en mariela, donde la
coincidencia es casualidad).

La fuente autoritativa son las líneas de `restore.sql`:

```sql
COPY t05_viajes (cuit_empresa, dominio, numero_viaje, ...) FROM '$$PATH$$/2530.dat';
```

`LegacyBackupReader` indexa por esa línea. La prohibición de emparejar por posición está
documentada en la clase, para que nadie la reintroduzca.

---

## 3. Conteos verificados

Reproducidos con `scripts/legacy-transporte-verify.php` sobre los cuatro archivos:

| | ariel | gisela | mario | mariela |
|---|---|---|---|---|
| Tablas | 103 | 103 | 109 | 109 |
| `t05_viajes` | 0 | 0 | **3** | **1.740** |
| `t06_origenes_destinos_por_viaje` | 0 | 0 | 4 | 4.000 |
| `t07_cargas_combustible_por_viaje` | 0 | 1 | 8 | 10.002 |
| `t08_gastos_por_viaje` | 0 | 0 | 9 | 8.022 |
| `t11_cuenta_corriente_x_viaje` | 0 | 0 | 7 | 10.113 |
| `t12_cuenta_corriente_origenes_destinos_por_viaje` | 0 | 0 | 1 | 1.242 |
| `t13_otros_haberes_por_viaje` | 0 | 0 | 8 | 3.185 |
| `t01_choferes` | 3 | 0 | 7 | 100 |
| `t02_dominios` | 3 | 0 | 8 | **28** |
| `t03_origen_destino` | 0 | 0 | 4 | 1.106 |
| `t04_conceptos_choferes` | 0 | 0 | **97** | 0 |

Todas las afirmaciones del encargo se reproducen **exactamente**:

- 28 dominios en mariela: **14 formato argentino, 13 Mercosur Brasil, 1 sin clasificar** (`AA PRUEBA`).
- Viajes de mariela: **1.237 AR · 501 BR · 2 sin clasificar** (los dos de `AA PRUEBA`).
- ariel y gisela no tienen viajes; mario tiene **3**.
- **8** viajes con `fecha_regreso` anterior a `fecha_salida`.
- **1** viaje con `fecha_salida` (2026-10-21) posterior a la fecha del backup (2026-10-06).
- Filas hijas sin viaje: `t06`=2, `t07`=1, `t08`=3. Todas con `cuit_empresa`, `dominio`
  vacíos y `numero_viaje = 0`. **Son 6, no 2.**

### 3.1 Clave de origen

La PK declarada del legacy es exactamente la clave pedida:

```sql
ALTER TABLE ONLY t05_viajes ADD CONSTRAINT t05_x_clave PRIMARY KEY (cuit_empresa, dominio, numero_viaje);
```

Hija: `(cuit_empresa, dominio, numero_viaje, item)`.

**0 colisiones** de `(cuit_empresa, dominio, numero_viaje)` dentro de cada archivo y entre
los cuatro. En cambio hay **111 números de viaje repetidos entre dominios distintos**, y
los números 1 y 2 existen bajo dos CUIT diferentes (23-28059786-4 de mariela y
20-11032975-0 de mario). Deduplicar por número, patente, cliente o fechaaría fusionar viajes
distintos.

---

## 4. Evidencia de país para la sucursal

La patente no alcanza. Siete dominios (`GZD 587`, `JLH 877`, `HLD283`, `OIU 270`,
`OJG 526`, `HWW 968`, `NEB021`) tienen formato de 6+3 caracteres que usaron Argentina y
Brasil antes de Mercosur: **529 viajes, 30% del total**.

La evidencia operativa viene de `t06.descripcion_origen`, `descripcion_destino` y
`observaciones`. Menciones de destinoBrasil por dominio:

| Grupo | Viajes | Tramos | DestinosBrasil |
|---|---|---|---|
| 13dominios Mercosur-BR | 501 | ~1.000 | IUU5G88 65/137 · IWE2I14 56/120 · ITV9J84 53/136 · IUR1E71 51/100 |
| 7 dominios 6 caracteres | **529** | ~1.240 | JLH 877 30/217 · HWW 968 30/194 · OJG 526 24/215 |
| 7 dominios Mercosur-AR | 708 | ~1.718 | AC 532 DD 35/261 · AF 081 MJ 36/240 |

La diferencia real no está en el volumen de mencionesBrasil, sino en **el origen**: los
13 trucks Mercosur-BR salen de base Brasilera (Dionisio Cerqueira, Tres Passos, Umbuarama,
Palotina, Ponte Grossa, Itaquiraí). Los 7 ambiguos salen de base argentina (Mendoza,
Oberá, San Juan, Buenos Aires). `NEB021` es el caso más débil: 24 viajes, 53 tramos,
1 sola mención Brasil.

**Conclusión propuesta:** los 7 se asignan a **TSA Argentina**, con la evidencia registrada
y **validación obligatoria en la vista previa**. `NEB021` se marca como caso de mayor
duda. Ningún caso se corrige ni se descarta en silencio.

---

## 5. Clientes

`t06.codigo_cliente` usa códigos numéricos del legacy. La resolución es directa contra
el padrón canónico:

```sql
UNIQUE (empresa_id, origen, codigo_origen)   -- tabla `clientes`
```

con `origen = transporte_mario_pawluk`. Ese padrón se construye desde
`clientes_mario_para_importar.csv` (356 filas).

Verificación de solape: de los **240 códigos** que usa mariela en `t06`, **239 existen** en
el CSV. **El único sin correspondencia es el código `0`**, que queda pendiente de revisión
humana sin perder sus filas.

### 5.1 Advertencia sobre el estado del padrón

`resumen.json` de esa misma carpeta dice:

> `"estado": "EXTRACCION PARA REVISION. NO IMPORTADO AL SISTEMA."`

y el documento de evidencia de la rama (`docs/EVIDENCIA_IMPORTACION_CLIENTES_2026-10-16.md`)
cierra con:

> "No se subieron archivos a Ferozo ni se importaron clientes reales."

El código del importador de clientes existe en la rama `codex/importacion-clientes`, pero
**no está en `origin/main` y no hay evidencia de que el padrón esté cargado en ninguna
base**. Si ese padrón todavía no está en la base, los 240 códigos no tienen contra qué
resolverse y el importador de Viajes no puede confirmar viajes con `codigo_cliente`.

---

## 6. Decisiones tomadas en esta entrega

| Decisión | Estado |
|---|---|
| D3 revisada: **sí migrar** `t11`/`t12`/`t13` | **Revertida por el usuario.** Se importan como histórico preservando columnas, monedas y cotización originales, **sin calcular saldos ni totales derivados**. Contradice el análisis previo, que las descartaba por monedas inconsistentes. |
| Clave de origen | `(cuit_empresa, dominio, numero_viaje)`, la PK real del legacy |
| Formato de ingesta | Conversión previa a ZIP + CSV + `manifest.json`. Nada de `pg_restore` en producción |
| Nulos y relleno | Se preserva `\N` como NULL y el relleno de `char(n)`. No se normalizan, porque normalizar rompe la fidelidad con el origen |
| Base de trabajo | `codex/importacion-clientes`, porque `clientes` canónico solo existe ahí |

---

## 7. Lo que falta y qué decisión humana lo bloquea

### 7.1 El importador de Viajes

Falta todo el núcleo: modelo de dominio, migraciones de `viajes` + hijos, lectura del
paquete, vista previa con conteos y errores por fila, confirmación transaccional e
idempotencia por `(empresa_id, origen, cuit, dominio, numero_viaje)`.

**Bloqueo 1 — base de la rama.** El encargo indicaba partir de `origin/main`, pero
`clientes` canónico **no existe ahí**: solo está en `codex/importacion-clientes`, que
tampoco está mergeada. Se partió de esa rama para no duplicar el padrón. Si preferís
`origin/main`, hay que decidir qué se hace con `clientes`: esperar el merge, o absorberlo.

**Bloqueo 2 — despliegue de clientes.** Sin el padrón cargado (ver §5.1), los viajes con
`codigo_cliente` no se pueden confirmar. Hay que confirmar si ya se importó o si el
importador de Viajes debe resolver el padrón él mismo.

### 7.2 Casos que quedan marcados en la vista previa

- `AA PRUEBA`: 2 viajes, patente de prueba, sin clasificar. No se asigna sucursal.
- `NEB021`: 24 viajes, evidencia de país más débil.
- Los 6 viajes con `fecha_regreso` anterior a `fecha_salida` y el de `fecha_salida`
  posterior al backup: se muestran, no se corrigen.
- 6 filas hijas huérfanas (`t06`=2, `t07`=1, `t08`=3): se conservan enteras como registro
  pendiente, sin colgar de un viaje inexistente.
- Código de cliente `0`: sin correspondencia en el padrón.

### 7.3 Adjuntos

Ninguno de los cuatro backups trae archivos binarios adjuntos. Las referencias de
remito existen (`t06.remito`) pero no hay adjuntos que recuperar. No hay nada que reportar
como perdido porque nunca estuvieron.

---

## 8. Herramientas entregadas

| Archivo | Qué hace |
|---|---|
| `app/Legacy/Transporte/LegacyBackupReader.php` | Lee un `.backup`, indexa por `restore.sql`, decodifica COPY, expone PKs. Sin CodeIgniter, sin DB |
| `scripts/legacy-transporte-build.php` | Convierte uno o más `.backup` en ZIP portable (`manifest.json` + `data/<tabla>.csv`) |
| `scripts/legacy-transporte-verify.php` | Verifica el lector contra los backups reales. **23 comprobaciones, 0 fallas** |
| `tests/unit/Legacy/Transporte/LegacyBackupReaderTest.php` | Pruebas PHPUnit del formato y del mapeo |

### Dónde se ejecuta

En cualquier máquina con **PHP 8.2+ y ext-zip**: la estación de trabajo o un contenedor.

```bash
php scripts/legacy-transporte-build.php --salida=build/transporte-viajes \
    transporte_ariel_pawluk-20261006.backup \
    transporte_gisela_pawluk-20261006.backup \
    transporte_mario_pawluk-20261006.backup \
    transporte_mariela_pawluk-20261006.backup
```

Resultado de esta corrida: **15 tablas por backup, 60 tablas y 39.762 filas** en total.

### Alcance del paquete

El paquete sale con `--alcance=viajes` (por defecto): **solo el agregado de Viajes y las
maestras que sus columnas referencian**. No se crean tablas nuevas: se conservan las
nombres y columnas del legacy, para que la transformación sea reversible y auditable.

| Rol | Tablas | Filas (mariela) |
|---|---|---|
| `viaje` | `t05_viajes`, `t06_origenes_destinos_por_viaje`, `t07_cargas_combustible_por_viaje`, `t08_gastos_por_viaje`, `t11_cuenta_corriente_x_viaje`, `t12_cuenta_corriente_origenes_destinos_por_viaje`, `t13_otros_haberes_por_viaje` | 38.304 |
| `maestra` | `t01_choferes`, `t02_dominios`, `t03_origen_destino`, `t04_conceptos_choferes`, `t09_empresas`, `t10_dominios_por_empresa`, `t14_cuit_paises`, `t18_monedas` | 1.266 |

`--alcance=completo` existe solo para inspección: genera las 109 tablas del dump
(58.430 filas en total) y **no debe subirse al importador**.

El padrón de clientes **no viaja en el paquete**: se carga aparte desde
`clientes_mario_para_importar.csv` y se resuelve por `(empresa_id, origen, codigo_origen)`.

**Tamaño del paquete de mariela: 0,55 MB**, muy por debajo del tope de 10 MB que aplica
`LocalPrivateImportFileStorage`.

El paquete se sube al hosting y **lo consume la aplicación por HTTP**. Ferozo nunca
ejecuta la conversión: no hay shell, ni `php spark`, ni `pg_restore`.

### Convenciones del paquete

- `manifest.json`: archivo de origen, `sha256`, tablas, columnas, PKs y conteos por tabla.
- `data/<tabla>.csv`: una fila de encabezado con los nombres de columna exactos del legacy.
- `\N` representa SQL NULL. El relleno de `char(n)` se conserva tal cual.

---

## 9. Validaciones ejecutadas

```bash
php -l app/Legacy/Transporte/LegacyBackupReader.php      # sin errores
php -l scripts/legacy-transporte-build.php                # sin errores
php -l scripts/legacy-transporte-verify.php               # sin errores
php -l tests/unit/Legacy/Transporte/LegacyBackupReaderTest.php  # sin errores
php scripts/legacy-transporte-verify.php <los 4 backups>  # 23 correctas, 0 fallidas
```

Ejecutadas con **PHP 8.5.4 en WSL**, no con el PHP/MariaDB local de Windows.

### Lo que NO se pudo validar

- **La suite PHPUnit no pudo ejecutarse en este worktree.** `vendor/` no existe en un
  worktree y no hay binario de Composer disponible para regenerar el autoloader. El
  archivo `vendor/composer/autoload_psr4.php` resuelve bien `App\`, pero la clase no llega
  a cargarse en el proceso de PHPUnit. El archivo de prueba está escrito y con sintaxis
  válida, pero **no se ejecutó**: hay que correrlo en un checkout con `vendor/` propio o
  en staging `fasa_189` según `AGENTS.md`.
- **No se tocó ninguna base.** No se ejecutaron migraciones, no se corrieron pruebas
  contra MariaDB y no se usó `fasa_189` ni `fasa_195`.
- **No se cargó ningún dato productivo ni se desplegó a Ferozo.**
- No se validó la suite general del repositorio, que según la evidencia de clientes ya
  arrastra errores previos ajenos a este cambio.