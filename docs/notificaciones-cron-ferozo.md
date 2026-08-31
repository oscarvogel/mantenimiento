# Cron de notificaciones en Ferozo

> **ENTORNO CANÓNICO DE STAGING: `fasa_189`. Mantenimiento staging corre exclusivamente en Docker/Coolify sobre `fasa_189`. No buscar ni operar `fasa_195` salvo instrucción explícita.**

```text
staging = fasa_189 / Docker / Coolify
producción = Ferozo / FTPS / sin CLI
```

El hosting de producción no dispone de SSH ni de PHP CLI. Por ese motivo, `php spark notifications:dispatch` queda como herramienta de desarrollo/staging y producción utiliza un endpoint HTTPS protegido que ejecuta el mismo caso de uso.

## Configuración de producción

En el `.env` real, no versionado:

```ini
app.appTimezone = America/Argentina/Buenos_Aires
alerts.webCronEnabled = true
alerts.webCronToken = <SECRETO_ALEATORIO_DE_AL_MENOS_32_CARACTERES>
alerts.webCronRateLimit = 6
alerts.webCronRateWindowSeconds = 60
alerts.lockTimeoutSeconds = 900
```

Generar el secreto fuera del servidor, por ejemplo:

```bash
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

No reutilizar `MIGRATE_TOKEN` ni ninguna contraseña SMTP/DB.

## Endpoint nuevo

El endpoint seguro es:

```text
POST https://vogelconsultoria.com.ar/mantenimiento/internal/cron/notifications/dispatch
X-Cron-Token: <SECRETO_ALEATORIO_DE_AL_MENOS_32_CARACTERES>
```

También acepta `Authorization: Bearer <TOKEN>` para clientes que no permitan
`X-Cron-Token`. No se aceptan tokens en query string ni en el cuerpo de la
solicitud. La respuesta sólo contiene estado, clave de ejecución y contadores
técnicos; nunca emails, tokens, secretos ni trazas.

Los estados previstos son: `404` si está deshabilitado, `401` si falta el
token, `403` si es incorrecto, `405` para métodos no permitidos, `409` si ya
hay un despacho activo, `429` si se excede el límite por IP y `500` ante una
falla técnica. Un éxito devuelve `200` con `overdue`, `collected`, `sent`,
`retry`, `skipped` y `errors`.

El panel real de Ferozo todavía debe verificarse. No asumir que sus tareas
programadas soportan POST o headers personalizados; la decisión del mecanismo
productivo queda pendiente de esa comprobación.

## URL legacy conservada

Se mantiene temporalmente:

```text
GET https://vogelconsultoria.com.ar/mantenimiento/cron/notificaciones/<TOKEN>
```

Está marcada como legacy/deprecated y comparte lock, idempotencia, reintentos,
rate limiting y trazabilidad con el endpoint nuevo. No retirarla ni migrar la
tarea existente hasta revisar el panel real de Ferozo y confirmar que ninguna
configuración depende de ella. Una vez confirmado, deshabilitar la tarea
legacy, rotar el secreto si fue expuesto en URLs/logs y retirar la ruta en un
PR separado.

## Garantías operativas

El endpoint no implementa una segunda lógica de notificaciones. Llama a `RunNotificationCycle`, el mismo ciclo usado por el comando CLI:

1. detectar vencimientos;
2. recolectar eventos notificables;
3. despachar email/Web Push;
4. aplicar lock, idempotencia, reintentos y trazabilidad existentes.

Dos ejecuciones con la misma clave horaria no deben duplicar entregas. Una ejecución concurrente debe ser rechazada por el control de proceso existente.

## Ejecución manual

Para desarrollo o staging con CLI disponible:

```bash
php spark notifications:dispatch
```

Para producción no se requiere consola. El backend expone además `POST /superadmin/notificaciones/despachar`, protegido por el filtro `superadmin`, para una futura/actual acción manual desde la interfaz administrativa.

## Smoke antes de producción

1. Validar primero en `fasa_189:8090` según `AGENTS.md`.
2. Configurar SMTP/VAPID sólo en el entorno de prueba.
3. Generar un preventivo próximo/vencido controlado.
4. Probar `POST` con header, ausencia de token, token incorrecto, `GET` y rate limiting.
5. Ejecutar el ciclo una vez y confirmar campana, email y push según preferencias.
6. Repetir la misma ejecución y confirmar ausencia de duplicados.
7. Forzar una falla temporal de canal y confirmar auditoría/reintento.
8. Recién después verificar el panel real de Ferozo y decidir el mecanismo productivo.

Nunca probar el primer disparo directamente contra producción.
