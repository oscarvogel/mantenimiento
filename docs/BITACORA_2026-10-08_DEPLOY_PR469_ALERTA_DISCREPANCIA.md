# Bitácora 2026-10-08 — Deploy PR #469 (alerta de discrepancia IA)

Quinto deploy del día. Agrega una alerta automática al Responsable de
mantenimiento cuando la lectura por foto muestra una discrepancia crítica entre
el odómetro detectado por la IA y el confirmado por el chofer.

| | |
|---|---|
| PR | #469 `feat: alertar discrepancias críticas entre IA y kilometraje confirmado` |
| TARGET_SHA | `bfbec406e01f5dd2294e599b2db193b4607ac275` |
| PROD anterior | `b310d2fa464cfd01872694c7c18af4238015ce31` (PR #468) |
| BASE | `1bbce5b74d73c84c524155d730d42a1df7a7faef` (merge de #468) |
| CI | run `37830951212` — completed / success, `headSha` = TARGET |
| Archivos subidos | 1 |

## La propiedad que más importaba comprobar

El requisito de fondo no era "que avise", sino que **la alerta jamás pueda
invalidar una lectura ya guardada**. Verificado leyendo el código desplegado:

```php
$database->transCommit();              // L460  lectura persistida
...
if ($this->shouldAlertAiDiscrepancy(...)) {   // L472
    try {
        $this->publishAiDiscrepancyAlert(...); // L474
    } catch (Throwable $notificationError) {   // L482
        // Una falla al notificar nunca debe invalidar una lectura ya persistida.
        log_message('error', 'No se pudo alertar discrepancia IA de lectura ...');
    }
}
```

La alerta está **fuera** de la transacción, después del commit, y su fallo solo
genera un log en nivel `error`. No hay `rethrow`. La lectura no se ve afectada.

Ese `error` además es visible con el threshold de producción (4), a diferencia
del `warning` de etapas anteriores que se perdían.

## La regla, verificada con los 3 casos

```
confidence >= 0.80   (AI_DISCREPANCY_MIN_CONFIDENCE)
|diferencia| >= 1000 km (AI_DISCREPANCY_MIN_KM)
ratio >= 0.05        (AI_DISCREPANCY_MIN_RATIO, sobre el odómetro detectado)
```

Las tres condiciones se evalúan con AND. Confirmado ejecutando el método real:

| caso | detected | confirmed | conf | resultado |
|---|---|---|---|---|
| real | 1.038.226 | 86.600 | 0.92 | `true` |
| corrección menor | 491.138 | 491.100 | 0.92 | `false` |
| baja confianza | 1.038.226 | 86.600 | 0.70 | `false` |

Más un caso con `detected = null` → `false`.

## El enlace de la notificación, verificado contra producción

Este punto tenía un antecedente: el commit `0aad890d` corrige la URL, que antes
estaba fija como `/mantenimiento/lecturas/{id}/evidencia`. Con
`base_url('mantenimiento/...')` y `app.baseURL` ya apuntando a `/mantenimiento/`,
el resultado en Ferozo lleva prefijo doble.

Se comprobó **contra el servidor**, no leyendo código:

| ruta | resultado |
|---|---|
| `/mantenimiento/mantenimiento/lecturas/1/evidencia` | **302 → login** (la ruta existe) |
| `/mantenimiento/lecturas/1/evidencia` | 404 |

O sea: el `parse_url(base_url(...), PHP_URL_PATH)` que usa el código es el que
resuelve. Con el path fijo anterior, el enlace de la notificación habría dado
404 en producción.

## Bug de método de verificación (importante)

El primer run de tests dio:

```
ReflectionException: Method App\Controllers\PublicEquipmentReadings::shouldAlertAiDiscrepancy() does not exist
```

**No era un bug del PR.** El método existe en el worktree (L512). La causa era
el montaje: al enlazar `vendor` desde el repo principal, el autoloader de
Composer resuelve `App\` contra el repo principal, que estaba en otro commit.

El detalle que lo hace confuso: Composer **falla** ante clases nuevas (el
analyzer no existe en main) y deja que el autoloader de CI4 las resuelva desde
el worktree; pero **acierta** ante clases que sí existen en main, cargando la
versión vieja. Por eso los tests del analyzer pasaban y este moría.

Se resolvió con un bootstrap que hace `setPsr4('App\\', [<worktree>/app])`.
Con eso: **15 tests, 117 assertions, OK**.

Conclusión sobre los deploys anteriores: sus tests eran correctos, porque usan
`file_get_contents(ROOTPATH . 'app/...')` (leen el archivo del worktree) o
clases nuevas. Ninguno instanciaba un controlador existente. Aun así, el
montaje queda corregido y documentado en
`tests/_support/worktree_bootstrap.php`.

## Verificación restante

- `php -l`: sin errores.
- Alcance: 1 runtime + 1 test. No se subieron tests, assets, migraciones ni `.env`.
- Remoto previo coincidió con `PROD` y `BASE`. Backup en `.deploy/backup-pre-bfbec406/`.
- `MATCH=1/1`, `HASH_VERIFICATION=OK`, revalidado al cierre.
- Smoke: `/login` 200, ruta pública con token inválido 200 controlado.
- Log sin errores nuevos.

## Pendientes

1. **Prueba funcional con DEMO98-CAM02.** Esperado: la lectura se guarda igual,
   la evidencia queda guardada, la pantalla muestra "Lectura registrada", y
   además aparece la notificación CRITICAL para el Responsable de mantenimiento
   con el resumen (IA, confirmado, diferencia, confianza) y el enlace a la
   evidencia.
2. **Ojo con el caso "sin destinatario".** Si no hay ningún usuario con el rol
   `Responsable de mantenimiento`, el código hace `return` con un
   `log_message('warning', ...)`. En producción el threshold es 4 y `warning` es
   nivel 5, así que **ese log no se escribe**. Si la alerta no aparece, puede ser
   eso y no un fallo: conviene confirmarlo con
   `MAINTENANCE_RESPONSIBLE_FOUND=NO` antes de suspectar el código.
3. **PR #469 sigue abierto** y `bfbec406` no está en `main`.
4. **El log del fallo de notificación sí es visible** (`error`), así que si la
   lectura se guarda pero la alerta no, se va a ver en `writable/logs` con
   "No se pudo alertar discrepancia IA de lectura".