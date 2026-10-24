# Diagnóstico 2026-10-08 — Fallo al registrar lectura por QR (DEMO98-CAM01)

Diagnóstico **solo lectura**. No se modificó código, no se subió nada, no se tocó
`.env`, no se ejecutaron migraciones, no se hizo commit.

Copia local del log: `.deploy/diag-466/log-2026-10-08.log`
(598 b, SHA256 `bd4d175f…`, 2 líneas).

## Lo que dice el log

El log de hoy tiene **solo dos líneas**, y las dos son el mismo error:

```
ERROR - 2026-10-08 14:36:54 --> Falló lectura QR anónima: is_dir(): open_basedir
restriction in effect. File(/data/priv) is not within the allowed path(s):
(/home/a0110632/storagedir:/home/a0110632:/tmp:/home/a0110632/tmpsite:/opt/php8-4/lib/php:…)
ERROR - 2026-10-08 15:24:41 --> Falló lectura QR anónima: is_dir(): open_basedir
restriction in effect. File(/data/priv) is not within the allowed path(s): (…)
```

`15:24:41` es exactamente el intento reportado.

**Dato clave: la ocurrencia de `14:36:54` es anterior a cualquier deploy de hoy.**
El error no lo introdujo el hotfix; venía de antes y el hotfix no lo tocó, porque
está en otra clase.

## Causa raíz

`app/Infrastructure/Measurement/ReadingEvidenceStorage.php:16`

```php
$defaultRoot = is_dir('/data/priv') || str_starts_with($projectRoot, '/var/www/')
    ? '/data/priv/lecturas'
    : dirname($projectRoot) . DIRECTORY_SEPARATOR . basename($projectRoot) . '-private' . ...;
$resolved = rtrim(trim($root ?? $defaultRoot), '\\/');
```

Ferozo corre PHP con `open_basedir` restringido a `/home/a0110632/…`. La llamada
`is_dir('/data/priv')` viola esa restricción y PHP emite `E_WARNING`. CodeIgniter
convierte warnings en excepciones: `system/Debug/Exceptions::errorHandler()` hace
`throw new ErrorException(...)` cuando `(error_reporting() & $severity) !== 0`.

Como el `||` cortocircuita en el **primer** operando, la línea 18 —que en Ferozo
habría resuelto bien a `/home/a0110632/storagedir/mantenimiento-private/uploads/lecturas`,
una ruta dentro de `open_basedir` y fuera del webroot— **nunca llega a evaluarse**.

Dos consecuencias:

1. El constructor es **incondicionalmente fatally** en Ferozo. Como `$defaultRoot` se
   calcula *antes* de la decisión `$root ?? $defaultRoot`, ni siquiera configurar
   `uploads.privatePath` en `.env` lo evitaría: la sonda se ejecuta igual.
2. Esto no es nuevo ni específico de Ferozo-vs-local: donde no hay `open_basedir`
   (Windows, Docker/Coolify) `is_dir('/data/priv')` devuelve `false` sin ruido y el
   bug queda latente.

## Dónde se rompe exactamente

En `PublicEquipmentReadings::store()` línea **322**, dentro del `else` porque no hay
evidencia staged:

```php
: $this->evidenceStorage()->store(...)   // L322  <- lanza aquí
  $database->transBegin();              // L327  <- nunca llega
```

Elthrow ocurre **antes** del `transBegin()`. Por eso:

- no hubo rollback (no había transacción abierta);
- no se insertó nada en `lecturas_equipo`, ni `lecturas_equipo_evidencias`, ni `qr_lecturas_auditoria` de éxito;
- no se actualizó `equipos`.

La excepción sale por el catch externo (L450), que loguea en `error` **y** llama a
`auditRejected()` (L456). Ese método sí escribe una fila `RECHAZADO` en
`qr_lecturas_auditoria` con el motivo. El mensaje que vio el usuario es
`register_failed` porque la excepción no es `DomainException` (L461).

## Por qué el staging falló en silencio

`analyze()` (L90) también construye el storage, pero el fallo está envuelto en un
`try/catch` que lo loguea como **`warning`** (L111) y sigue igual. En producción
`app/Config/Logger.php` tiene `threshold = 4` (solo emergency..error), y `warning`
es nivel 5 → **no se escribe**.

Por eso el log no contiene `No se pudo dejar staged la evidencia de lectura`: no
falló el log, el log está filtrado. El primer fallo real fue invisible y solo
afloró en el segundo paso, ya como `error`.

## La doble llamada a MiniMax (los ~20 s)

Es consecuencia, no causa. Como el staging falló, `evidence_ref` quedó `null`, así
que en `store()` el bloque `if ($stagedEvidence !== null)` no se cumple y se toma el
`else` (L245-254), que **vuelve a llamar a `MiniMaxOdometerImageAnalyzer::analyze()`**
con la misma foto. Segunda llamada de visión ≈ 20 s, compatible con lo observado.

En cuanto el storage funcione, queda evidencia staged y la segunda llamada
desaparece sola.

## Descartado por evidencia

- `VALIDATION`: km 122600 > km_actual 122500, y el salto (+100) está muy por debajo
  de `MAX_KM_JUMP = 5000`.
- `LARGE_JUMP`: no se alcanzó a evaluar el confirm; el salto es de 100.
- `RATE_LIMIT`: `RATE_LIMIT = 10` en 10 min; hubo 1-2 intentos.
- `EVIDENCE_INSERT` / `READING_INSERT` / `EQUIPMENT_UPDATE` / `AUDIT_INSERT`: no se
  alcanzó a SQL.
- `Data too long` / `Duplicate entry` / `SQLSTATE`: ausentes del log. El truncado a
  255 de `observacion_ia` del hotfix nunca llegó a importar, porque el insert no se
  intentó.

## Auditoría en BD

**Omitido por seguridad.** No existe una vía segura y ya existente de consulta de
solo lectura: Ferozo no tiene SSH ni CLI, las rutas de diagnóstico existentes
(`diagnosticos/vencimientos-regularizaciones`) son de negocio y requieren sesión, y
el único mecanismo de acceso a BD del proyecto es un helper HTTP temporal
(`migrate.php`) con capacidad de escritura, que está prohibido subir. No se creó ni
se subió nada.

Lo esperable por código es una fila `RECHAZADO` en `qr_lecturas_auditoria` a las
`15:24:41` con `motivo` = el mensaje de `open_basedir`, y `lectura_id` nulo.