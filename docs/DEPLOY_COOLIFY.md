# Deploy en Coolify — Staging aislado

> **Objetivo:** desplegar `main@0454e01` en Coolify de forma aislada para E2E #144/#146.
> **NO tocar producción Ferozo** (`vogelconsultoria.com.ar/mantenimiento`). **NO usar DB productiva.**

---

## 1. Arquitectura staging

- **App:** `Dockerfile` multi-stage (node:22 + php:8.4-apache) con layout plano (index.php + spark en raíz, sin `public/`).
- **Frontend:** Vite build dentro del Dockerfile → `assets/dashboard/.vite/manifest.json`.
- **DB:** MariaDB 11 recurso Coolify separado, interno `mariadb-staging:3306`.
- **Volumes Coolify:**
  - `/var/www/html/writable` → cache/logs/session (persistente, 755, www-data)
  - `/data/priv` → `adjuntos` + `importaciones` fuera del webroot (`uploads.privatePath=/data/priv/adjuntos`, `imports.privatePath=/data/priv/importaciones`)

## 2. Crear recursos en Coolify

1. **Proyecto** `mantenimiento` → **Environment** `staging` (nuevo, aislado de `production`).
2. **Application → Dockerfile:**
   - Repo `https://github.com/oscarvogel/mantenimiento`, Branch `main`, Commit `0454e01`, Auto Deploy OFF.
   - Build Pack: `Dockerfile` (raíz). Port `80`, Healthcheck `GET /login` 200.
3. **Database → MariaDB 11:** nombre `mantenimiento_staging`, user/pass generados, Network mismo que App.
4. **Domain:** `mantenimiento-staging.<dominio>` con HTTPS Let's Encrypt, Force HTTPS ON.

## 3. Variables (Coolify Secrets — nunca versionar)

Cargar exactamente estas keys (vaciar las no requeridas):

```
CI_ENVIRONMENT=production
app.baseURL=https://mantenimiento-staging.<dominio>/
app.forceGlobalSecureRequests=true
database.default.hostname=mariadb-staging
database.default.database=mantenimiento_staging
database.default.username=<generado por Coolify>
database.default.password=<generado>
database.default.DBDriver=MySQLi
database.default.port=3306
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

**Faltantes a cargar manualmente por vos:** `encryption.key` (generar), `database.*.password` (lo genera Coolify al crear DB, copiar al App), dominio real en `app.baseURL`, y si querés probar email real, `email.SMTP*`. **VAPID y AI key quedan deshabilitados** (`webpush.enabled=false`, `ai.enabled=false`) hasta tener claves reales.

## 4. Migraciones y seeders (una vez creado el App)

```bash
# dentro del contenedor App (Coolify Terminal o docker exec):
php spark migrate
php spark migrate:status   # debe listar 60 filas, última AddAiEnabledToEmpresas
php spark db:seed InitialSeeder           # idempotente: Empresa Demo + admin@mantenimiento.local / Admin1234
php spark db:seed DemoCompanySeeder       # opcional staging/demo para E2E
```

## 5. Build / verificación

Build ya corre en Dockerfile: `composer install --no-dev` + `npm ci && npm run build`.

Post-deploy:

```bash
curl -I https://mantenimiento-staging.<dominio>/login          # 200
curl -I https://mantenimiento-staging.<dominio>/.env            # 403
curl -I https://mantenimiento-staging.<dominio>/app             # 403 (coolify.conf)
```

Login debe cargar Vue + `assets/dashboard/assets/main-*.js|css` sin 404.

## 6. Job programado notifications:dispatch

- **Tipo:** Coolify **Scheduled Task** dentro del App (no HTTP).
- **Comando:** `php spark notifications:dispatch`
- **Schedule:** `0 10 * * *` (07:00 AR = 10:00 UTC, respeta `alerts.dailyRunTime=07:00`). Para prueba inicial podés usar `*/15 * * * *` y volver a diario.
- **Lock:** tabla `bloqueos_proceso` TTL 900s (`CodeIgniterNotificationProcessControl.php:22`), idempotencia `ejecuciones_programadas.clave_ejecucion=Y-m-d-H`.
- **Logs:** `writable/logs/*` + tabla `ejecuciones_programadas` (resumen JSON).
- **HTTP legacy:** `GET /cron/notificaciones/<TOKEN>` se conserva (`Routes.php:23`, `NotificationCron.php:14`) pero con `alerts.webCronEnabled=false` responde 404 en staging.

## 7. Apache / AllowOverride

`Dockerfile` habilita `a2enmod rewrite headers`, `AllowOverride All` y `DocumentRoot /var/www/html`. `docker/apache/coolify.conf` refuerza bloqueo `/app|/vendor|/writable` y `.env` a nivel Apache, además del `.htaccess` minimalista.

## 8. Qué NO hacer

- No apuntar staging a DB de producción.
- No copiar `SMTPHost/User/Pass` de producción a staging.
- No versionar `.env` ni `.ferozo-credentials`.
- No mergear este PR sin CI verde.
- No correr smoke #144/#146 hasta `login` 200 + `migrate:status` OK.

## 9. Rollback

En Coolify: Re-deploy al SHA anterior o `git revert` del merge. `writable` y `/data/priv` son volumes, no se pierden.
