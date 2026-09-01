# Deploy en Coolify — Staging aislado

> **ENTORNO CANÓNICO DE STAGING: `fasa_189`. Mantenimiento staging corre exclusivamente en Docker/Coolify sobre `fasa_189`. No buscar ni operar `fasa_195` salvo instrucción explícita.**

```text
staging = fasa_189 / Docker / Coolify
producción = Ferozo / FTPS / sin CLI
```

> **Objetivo:** desplegar y validar la rama `main` en el staging aislado de Coolify para E2E #144/#146.
> **Servidor obligatorio de pruebas:** `fasa_189` (`192.168.0.189`).
> **NO tocar producción Ferozo** (`vogelconsultoria.com.ar/mantenimiento`). **NO usar DB productiva.**

La configuración de prueba vigente vive en el proyecto `mantenimiento`, environment
`staging`, en `fasa_189`. No crear un Docker de producción en este proyecto: antes de
cualquier operación, `production` debe mostrar **0 recursos**. Si aparece cualquier
recurso allí, detenerse e informar el bloqueo.

`fasa_195` está fuera del circuito canónico de este proyecto. No inspeccionarlo ni
operarlo para tareas de mantenimiento salvo instrucción explícita del usuario.

---

## 1. Arquitectura staging

- **App:** `Dockerfile` multi-stage (node:22 + php:8.4-apache) con layout plano (index.php + spark en raíz, sin `public/`).
- **Frontend:** Vite build dentro del Dockerfile → `assets/dashboard/.vite/manifest.json`.
- **Servidor Coolify:** `localhost` dentro de `fasa_189`, red Docker `coolify`.
- **DB:** MariaDB 11 como recurso Coolify separado, privado y sin puerto público.
  El hostname interno lo genera Coolify; en la configuración vigente es
  `we7ppyik29cgwvfpf1plkw1y:3306`.
- **Volumes Coolify:**
  - `/var/www/html/writable` → cache/logs/session (persistente, 755, www-data)
  - `/data/priv` → `adjuntos` + `importaciones` fuera del webroot (`uploads.privatePath=/data/priv/adjuntos`, `imports.privatePath=/data/priv/importaciones`)

## 2. Crear recursos en Coolify

1. Verificar que el proyecto `mantenimiento` tenga `production = 0 recursos` y que
   los únicos recursos de prueba estén en `staging`.
2. **Application → Dockerfile:**
   - Recurso existente: `mantenimiento-staging`.
   - Repo `https://github.com/oscarvogel/mantenimiento`, Branch `main`, commit resuelto por Coolify.
     Registrar y confirmar el SHA completo de cada deploy; no usar un SHA histórico fijo.
   - Auto Deploy OFF: el deploy se inicia manualmente desde Coolify.
   - Build Pack: `Dockerfile` (raíz). Port `80`, Healthcheck `GET /login` 200.
3. **Database → MariaDB 11:** recurso existente `mantenimiento-mariadb-staging`,
   base `mantenimiento_staging`, credenciales propias y red `coolify` compartida con App.
4. **Domain vigente:** `http://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io/`.
   Si se reemplaza por un dominio HTTPS, actualizar `app.baseURL` y activar Force HTTPS.

## 3. Variables (Coolify Secrets — nunca versionar)

Cargar exactamente estas keys (vaciar las no requeridas):

```
CI_ENVIRONMENT=development
app.baseURL=http://bdqictyu4q5xkfuh6aoh7sr8.186.5.245.12.sslip.io/
app.forceGlobalSecureRequests=false
# En Coolify usar aliases con guion bajo para que Apache/PHP los herede:
database_default_hostname=we7ppyik29cgwvfpf1plkw1y
database_default_database=mantenimiento_staging
database_default_username=<generado por Coolify>
database_default_password=<generado>
database_default_DBDriver=MySQLi
database_default_port=3306
encryption.key=<php spark key:generate - 32 hex>
auth.maxLoginAttempts=5
auth.lockoutMinutes=15
email.protocol=smtp
email.SMTPHost=<smtp-prueba o vacio>
email.SMTPUser=<vacío en staging>
email.SMTPPass=<vacío>
email.SMTPPort=587
email.SMTPCrypto=tls
email.fromEmail=staging@<dominio>
email.fromName=Mantenimiento Staging
alerts.dailyRunTime=07:00
alerts.defaultAnticipationDays=15
alerts.lecturasVencidasDias=30
alerts.ordenDemoradaDias=5
alerts.lockTimeoutSeconds=900
alerts.webCronEnabled=false
alerts.webCronToken=
webpush.enabled=false
webpush.vapidPublicKey=
webpush.vapidPrivateKey=
webpush.subject=mailto:staging@<dominio>
uploads.privatePath=/data/priv/adjuntos
uploads.maxSizeMB=10
imports.privatePath=/data/priv/importaciones
imports.maxSizeMB=10
ai.enabled=false
```

**Faltantes a cargar manualmente:** `encryption.key` y la contraseña propia de la
MariaDB de staging. Nunca copiar credenciales de producción. Las claves VAPID y AI
quedan deshabilitadas (`webpush.enabled=false`, `ai.enabled=false`).

### Flags obligatorios de variables en Coolify

Todas estas variables deben quedar **Runtime: disponible en el contenedor** y
**Buildtime: no disponible durante el build**. Para la conexión de base de datos
usar los aliases `database_default_*`: Coolify conserva los nombres con puntos
para la terminal/CLI, pero no los entrega de forma confiable al proceso Apache.
CI4 resuelve esos aliases mediante `BaseConfig`, sin copiar secretos a la imagen.
No cambiar a variables de build para resolver un fallo del contenedor.

El valor `staging` no es un
entorno válido para el bootstrap de CI4: este Docker de prueba usa
`CI_ENVIRONMENT=development` y sigue siendo un recurso separado de producción.

## 4. FASE 6 — migraciones y seeders

No ejecutar esta sección hasta que ambos recursos estén `running/healthy`, el App
responda `/login` con HTTP 200 y el deploy muestre el SHA esperado.

```bash
# dentro del contenedor App (Coolify Terminal o docker exec):
php spark migrate
php spark migrate:status   # debe listar 60 filas; última migración actual del repo
php spark db:seed InitialSeeder           # catálogo base y permisos
php spark db:seed DemoCompanySeeder       # datos ficticios + usuario demo para E2E
```

Para todas las verificaciones manuales y E2E de staging usar exclusivamente el
usuario demo `demo@mantenimiento.local` con contraseña `Demo12345`. No usar el
usuario administrativo base como evidencia de aceptación.

## 5. Build / verificación

Build ya corre en Dockerfile: `composer install --no-dev` + `npm ci && npm run build`.

La imagen incluye `curl` y el healthcheck de Coolify usa el comando válido
`curl -fsS http://localhost/login`. No desactivar el healthcheck para ocultar un
fallo de la aplicación.

Post-deploy:

```bash
curl -I https://mantenimiento-staging.<dominio>/login          # 200
curl -I https://mantenimiento-staging.<dominio>/.env            # 403
curl -I https://mantenimiento-staging.<dominio>/app             # 403 (coolify.conf)
curl -I https://mantenimiento-staging.<dominio>/vendor          # 403/inaccesible
curl -I https://mantenimiento-staging.<dominio>/cron/notificaciones/probe # 404
```

Verificar además `/.env` con 403/inaccesible, `/app` y `/vendor` con 403/inaccesibles,
y todos los assets referenciados por `/login` sin 404. Completar un login funcional
con el usuario demo creado por `DemoCompanySeeder`.

## 6. Job programado notifications:dispatch

- **Precondición:** App `running/healthy`, `/login` 200 y migraciones verificadas.
- **Tipo:** Coolify **Scheduled Task** dentro del App (no HTTP).
- **Comando:** `php spark notifications:dispatch`
- **Schedule:** `0 10 * * *` (07:00 AR = 10:00 UTC, respeta `alerts.dailyRunTime=07:00`). Para prueba inicial podés usar `*/15 * * * *` y volver a diario.
- **Lock:** tabla `bloqueos_proceso` TTL 900s (`CodeIgniterNotificationProcessControl.php:22`), idempotencia `ejecuciones_programadas.clave_ejecucion=Y-m-d-H`.
- **Logs:** `writable/logs/*` + tabla `ejecuciones_programadas` (resumen JSON).
- **HTTP seguro:** `POST /internal/cron/notifications/dispatch` con `X-Cron-Token` (o `Authorization: Bearer`) devuelve sólo un resumen técnico redacted. `GET /internal/cron/notifications/dispatch` devuelve 405; token ausente 401; incorrecto 403; lock activo 409; rate limit 429. `alerts.webCronEnabled=false` devuelve 404.
- **HTTP legacy:** `GET /cron/notificaciones/<TOKEN>` se conserva y está deprecated (`Routes.php`, `NotificationCron.php`) hasta confirmar en el panel real de Ferozo que ninguna tarea existente depende de esa URL. Probar con un token ficticio; nunca exponer el token real.

## 7. Apache / AllowOverride

`Dockerfile` habilita `a2enmod rewrite headers`, `AllowOverride All` y `DocumentRoot /var/www/html`. `docker/apache/coolify.conf` refuerza bloqueo `/app|/vendor|/writable` y `.env` a nivel Apache, además del `.htaccess` minimalista.

## 8. Qué NO hacer

- No crear, iniciar ni desplegar una Application/Database en el environment `production` de este proyecto.
- No usar otro host ni PHP/MariaDB local de Windows para esta prueba; el único
  destino canónico es `fasa_189` mediante Docker/Coolify.
- No apuntar staging a DB de producción.
- No copiar `SMTPHost/User/Pass` de producción a staging.
- No versionar `.env` ni `.ferozo-credentials`.
- No mergear este PR sin CI verde.
- No correr smoke #144/#146 hasta `running/healthy` en App y MariaDB, SHA confirmado,
  `/login` 200, `migrate:status` OK y las verificaciones HTTP/seguridad aprobadas.

## 9. Informe de salida

Solo informar **STAGING LISTO PARA SMOKE #144/#146** cuando se hayan aprobado todos
los gates anteriores. En caso contrario, informar el bloqueo exacto, conservar el
deploy fallido en Coolify y no ejecutar el smoke completo.

## 10. Rollback

En Coolify: Re-deploy al SHA anterior o `git revert` del merge. `writable` y `/data/priv` son volumes, no se pierden.
