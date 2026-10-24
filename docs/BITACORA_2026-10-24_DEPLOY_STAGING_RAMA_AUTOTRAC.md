# Bitácora — deploy de staging de la rama `feat/telemetria-autotrac-atc`

**Fecha:** 24/10/2026
**Destino:** `fasa_189` (Docker / Coolify), stack `bdqictyu4q5xkfuh6aoh7sr8` (`mantenimiento-staging`)
**Rama:** `feat/telemetria-autotrac-atc` · **SHA:** `93f1e38ed22e90450ddbc5d061bb0949d33053bc`
**Alcance:** solo staging. **Producción no se tocó.**

---

## 1. Qué se desplegó

Los 10 commits de la rama, que subspaces el trabajo que estaba sin commitear en el
workspace principal (Autotrac/ATIC, lectura histórica, guard de piloto, submenú
plegable de telemetría, documentación y el generador de PDF).

El build de la imagen tardó ~13 minutos y terminó con `New container is healthy` y
rolling update completo. El contenedor anterior se eliminó solo.

```
contenedor: bdqictyu4q5xkfuh6aoh7sr8-20261024T124210
imagen    : bdqictyu4q5xkfuh6aoh7sr8:93f1e38ed22e90450ddbc5d061bb0949d33053bc
salud     : healthy
```

## 2. Procedimiento

Staging no se despliega con un release completo: se cambia la referencia de la
aplicación de Coolify y se dispara un rebuild.

1. Crear un **PAT temporal de Sanctum** desde dentro del contenedor `coolify`,
   con `abilities => ["*"]` y `expires_at` a 2 horas. El token en claro se
   escribe en `/tmp/pat.txt` (mode 600) **dentro del contenedor**.
2. `PATCH http://127.0.0.1:8080/api/v1/applications/bdqictyu4q5xkfuh6aoh7sr8`
   con `git_branch` y `git_commit_sha`.
3. `POST http://127.0.0.1:8080/api/v1/applications/bdqictyu4q5xkfuh6aoh7sr8/start`
   con `force` e `instant_deploy`.
4. **Borrar el PAT**: se elimina la fila de `personal_access_tokens` y el
   `/tmp/pat.txt`. El token nunca sale del host.

```bash
# estado del deploy (tabla real: application_deployment_queues)
docker exec coolify-db psql -U "$PGU" -d "$PGD" -tA -c \
  "select status from application_deployment_queues where deployment_uuid='<uuid>';"
```

### Rollback a `main`

El script queda en `%TEMP%\coolify_rollback.sh` y hace el PATCH a
`git_branch=main`, `git_commit_sha=HEAD` y vuelve a disparar el deploy. **No se
ejecutó**: staging queda en la rama, que es el objetivo de esta fase.

## 3. Verificación (los gates de `DEPLOY_COOLIFY.md` §5)

| Chequeo | Resultado |
|---|---|
| `GET /login` | **200** |
| `GET /.env` · `/app` · `/vendor` · `/writable` | **403** en los cuatro |
| `GET /notificaciones/resumen` | **302** al login, sin fatal |
| `GET /mantenimiento/ordenes` · `/ordenes/importar` · `/lecturas/control` | **302** |
| Bundles del manifest contra el disco | 22 referenciados, **0 faltantes**, HTTP 200 |
| `php spark migrate:status` | **0 pendientes** (última: `2026-10-23-150000 AddDriverSettlement`) |
| Log de hoy | **sin una sola entrada**: ni CRITICAL ni ERROR |

> En staging **no** aplica el prefijo `/mantenimiento` de las URLs: el
> `app.baseURL` es `https://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io/`.
> Las rutas de órdenes sí viven bajo el grupo `mantenimiento`, las de
> notificaciones en la raíz.

El código de la rama está efectivamente en la imagen: la ruta `autotrac/probar`
en `Routes.php`, las cinco clases `Autotrac*`, la firma nueva de
`ClosePreventiveOrder::execute()` con `$registerReading`, `forcesPilotMode` en el
store de notificaciones y el submenú plegable en `main-nEZar5hv.js`.

## 4. Comprobación funcional contra la base real

### Guard de piloto (fix de notificaciones)

```
source                 = 'database'
whatsapp_enabled       = false
whatsapp_pilot_enabled = true
whatsapp_pilot_phone   = '3743667526'
smtp_enabled           = false
CI_ENVIRONMENT         = 'development'
```

Con `CI_ENVIRONMENT=development` el guard fuerza el modo piloto **por código**:
staging no puede mandar a teléfonos reales aunque alguien habilite el canal. En
producción el guard es un no-op funcional, porque `CI_ENVIRONMENT=production` no
fuerza piloto y ahí el canal correcto es estar apagado.

### Fix de lecturas históricas contra MariaDB real

Se exercised el caso de uso real (`ConfirmWorkOrderDocumentImport`) con equipos
reales de la base de staging (MariaDB 11.8.9):

| Equipo | km_actual | doc histórico (km−50000, de hace 2 años) | doc de hoy |
|---|---|---|---|
| JLH877 | 1.718.119 | **PRESERVA** | aplica |
| OJG526 | 1.216.168 | **PRESERVA** | aplica |
| HLD283 | 1.865.007 | **PRESERVA** | aplica |
| HWW968 | 1.708.407 | **PRESERVA** | aplica |
| OIU270 | 1.193.638 | **PRESERVA** | aplica |
| AC532DD | 1.030.000 | **PRESERVA** | aplica |

Una lectura documentada de hace 2 años que retrocede 50.000 km **no pisa la foto
del equipo**; una lectura de hoy sigue el camino normal. El fix está verificado
contra los datos reales, no solo contra el código.

> Lo que **no** se ejercitó: el import end-to-end por HTTP. El importador exige
> un JPG, PNG o PDF y lo analiza por OCR, así que probarlo pediría un documento
> real y el proveedor de análisis configurado en esa empresa.

## 5. Auditoría de producción por FTPS (solo lectura)

Se comparó, byte a byte y normalizando finales de línea, lo que hay en
producción contra `origin/main` y contra la rama:

| Archivo | Producción | vs `main` | vs rama |
|---|---|---|---|
| `app/Application/MaintenanceCircuit/ClosePreventiveOrder.php` | `ab81f362aa3034fe` | distinto | **= rama** |
| `app/Application/MaintenanceCircuit/Port/PreventiveOrderClosurePort.php` | `42b224490b1d4605` | distinto | **= rama** |
| `app/Infrastructure/MaintenanceCircuit/CodeIgniterPreventiveOrderClosure.php` | `22c8be046f678be3` | distinto | **= rama** |
| `app/Application/WorkOrders/DocumentImport/ConfirmWorkOrderDocumentImport.php` | `8555d05d4120f213` | distinto | **= rama** |
| `app/Infrastructure/Notifications/CodeIgniterGlobalNotificationSettingsStore.php` | `5d868fb8036fa527` | **= main** | distinto |
| `app/Config/Services.php` | `d6a0d8af28dc8d4a` | distinto | distinto |
| `app/Config/Routes.php` | `8516b661069662b0` | distinto | distinto |

**Hallazgo principal: el fix de lecturas históricas ya está en producción.**
Los cuatro archivos coinciden byte a byte con la rama, y `main` no los tiene.
O sea que producción corre un fix que nunca estuvo commiteado; esta rama lo
vuelve a poner en git, pero **`main` todavía no lo tiene**.

**Consecuencias:**

- Para producción **no queda nada del fix de lecturas**: ya está.
- Lo único pendiente de subir sería **un archivo**, el store de notificaciones
  con el guard de piloto (1 archivo, no-op funcional en producción).
- `Services.php` y `Routes.php` de producción **no coinciden con ningún branch**.
  Subirlos, como parte de un release, es justamente lo que rompió producción el
  23/10. No tocar.

## 6. Pendientes

1. **`feat(telemetria)` (Autotrac) no va a producción todavía.** Necesita
   credenciales de ATIC, una fila en la tabla de integraciones y una prueba
   contra la API real. Hoy está desplegado solo en staging, sin configurar.
2. **Staging conserva las credenciales SMTP de producción** en el env del
   contenedor (`email.SMTPUser=info@vogelconsultoria.com.ar`,
   `email.SMTPPass`). El canal está apagado en la base (`smtp_enabled=false`),
   pero la credencial sigue montada. Es el paso 2 del P0 de la bitácora del
   23/10 que quedó a medias; se resuelve en Coolify, no por código.
3. **El `fromName` de la base dice "Mantenimiento"**, no "Mantenimiento
   Staging". El valor del env sí dice "Mantenimiento Staging", pero la base lo
   pisa. Si alguna vez se habilita el SMTP en staging, saldría con el nombre
   indistinguible y desde el buzón de producción.
4. **`main` no tiene el fix de lecturas que ya corre en producción.** Merge de
   la rama o cherry-pick de `fe939082` para que git refleje lo que la app
   realmente ejecuta.
5. **La migración `2026-10-23-140000 AddManagementReadingReportSettings` sigue
   sin aplicarse en producción** (en staging está aplicada).
