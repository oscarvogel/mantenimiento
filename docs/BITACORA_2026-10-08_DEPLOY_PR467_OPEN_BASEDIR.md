# Bitácora 2026-10-08 — Deploy PR #467 (open_basedir / almacenamiento Ferozo)

Tercer deploy del día, y el que cierra el diagnóstico de
[DIAGNOSTICO_2026-10-08_QR_READING_FAILURE.md](DIAGNOSTICO_2026-10-08_QR_READING_FAILURE.md).
El código ya estaba implementado y con CI verde; este registro es la
verificación del despliegue y de la evidencia que lo respalda.

| | |
|---|---|
| PR | #467 `fix: almacenamiento de evidencias compatible con Ferozo open_basedir` |
| TARGET_SHA | `fae37e6c3159baabc1cd18c487d04158d2fc8fbb` |
| PROD anterior | `670a83fb9002a03e9cf569566d9bc4cc55c46793` (PR #466 v2) |
| BASE | `c18b533c7867bd522e9379e74b5525c4c463a83e` (`main`) |
| CI | run `37825493669` — completed / success, `headSha` = TARGET |
| Archivos subidos | 2 |

## Qué cambió

El delta real contra producción eran 3 archivos: los 2 runtime y un test.

**`ReadingEvidenceStorage.php`** — se eliminó la sonda `is_dir('/data/priv')`. La
raíz por defecto ahora siempre se deriva de `ROOTPATH`. Es la solución de fondo:
no se toca el filesystem para decidir dónde guardar.

**`PublicEquipmentReadings.php`** — dos cambios:

1. `evidenceStorage()` prioriza `uploads.readingEvidencePath`; si no está, deriva
   `lecturas` como hermana de `uploads.privatePath`; si no, usa el default.
   Ninguna ruta decideCalling `is_dir`.
2. El fallo de staging pasó de `log_message('warning')` a `log_message('error')`.

El punto 2 es el que más importa a futuro: con `Logger.php` en
`threshold = 4` (solo emergency..error), el `warning` anterior **nunca se
escribía**. El primer fallo real quedaba invisible y solo afloraba en el paso
siguiente, ya como `error`. Ese fue el motivo de perder el rastro durante horas.

Un detalle bueno del diseño: en Coolify `uploads.privatePath=/data/priv/adjuntos`,
y la derivación nueva produce `/data/priv/lecturas` — exactamente la intención
original del código eliminado, pero sin sondear.

## Verificación

### Alcance del cambio

`git diff --name-only 670a83fb..fae37e6c` = 2 runtime + 1 test. Coincide con lo
pedido. `php -l` sin errores en ambos archivos.

### Backup y comparación previa

Los 2 remotos cayeron en estados conocidos (`PROD` y `BASE` respectivamente), así
que no había drift. Backup en `.deploy/backup-pre-fae37e6c/`.

### Upload

```
[1/2] PublicEquipmentReadings.php        7aec9959…  34980 b  COINCIDE=SI
[2/2] ReadingEvidenceStorage.php        333e6527…   3686 b  COINCIDE=SI
HASH_VERIFICATION=OK   MATCH=2/2
```

Al cierre se revalidaron **3/3**: los 2 del PR #467 más el
`MiniMaxOdometerImageAnalyzer`, que este PR **no** toca y que quedó intacto
(`6ced081a…`).

### La prueba que faltaba en el diagnóstico anterior

Antes de subir, se reprodujo el fallo y el arreglo **bajo `open_basedir` real**,
con un error handler estilo CI4 y `ROOTPATH` simulado de Ferozo:

```
PRODUCCION ACTUAL (670a83fb)
  LANZA ErrorException
  is_dir(): open_basedir restriction in effect. File(/data/priv) is not within…

PR #467 (fae37e6c)
  CONSTRUIDO OK
  raiz resuelta: /home/a0110632/storagedir/mantenimiento-private/uploads/lecturas
```

La raíz resuelta cae **dentro** de `open_basedir` (`/home/a0110632/storagedir…`)
y **fuera** del webroot. Es el resultado correcto, verificado en la condición
hostil y no solo por lectura de código.

### Tests

`tests/unit/Infrastructure/Measurement/`: **12 tests, 89 assertions, OK**. Incluye
los dos guards nuevos:

- `Evidence storage does not probe docker only path`
- `Controller prefers configured evidence path and logs staging failure as error`

Se comprobó que muerden: reintroduciendo `is_dir('/data/priv')` en el worktree, el
primer test falla con `does not contain "is_dir('/data/priv')"`. Restaurado y
verificado por hash (`333e6527…`).

### Smoke y logs

`/login` → 200. Ruta pública con token inválido → 200 controlado. Log de hoy sigue
con las 2 líneas viejas (14:36 y 15:24), **0 errores nuevos**.

## Límite honesto de la verificación

Los dos guards nuevos son **estáticos**: comprueban el texto del fuente, no el
comportamiento. Evitan reintroducir el literal exacto, pero no detectarían una
regresión funcional equivalente que sondee *otra* ruta externa.

La prueba fuerte de este fix fue el probe con `open_basedir` real
(`.deploy/diag-466/storage_fix_probe.php`), que es comportamental y sí falla si el
código vuelve a tocar el filesystem para decidir la raíz. Si se quiere dejar el
riesgo cerrado en la suite, ese probe debería volverse un test.

## Pendientes

1. **Prueba funcional con la foto real.** Es lo único que falta. Lo esperable: una
   sola llamada a la IA, evidencia staged, y registro rápido (los ~20 s eran la
   segunda llamada a MiniMax, que se disparaba por la falta de evidencia staged).
   Si vuelve a fallar, ahora **sí** queda logueado como `error` y se va a ver en
   `writable/logs`.
2. **PR #467 sigue abierto**, igual que #466 que ya fue mergeado a la rama.
   `fae37e6c` no está en `main`. Un redeploy routine desde `main` revierte el fix.
3. **Confirmar dónde cayó la evidencia.** El default deriva a
   `…/mantenimiento-private/uploads/lecturas`. Si `uploads.privatePath` estuviera
   dentro del webroot, `dirname($privatePath)/lecturas` caería también dentro.
   Vale confirmar que la carpeta queda fuera del alcance HTTP.
4. **Bug menor detectado de paso:** `ReflectionProperty::setAccessible()` está
   deprecado desde PHP 8.5 y ya no tiene efecto desde 8.1. Rompió el primer
   intento del probe; sirve de recordatorio para no usarlo en código nuevo.