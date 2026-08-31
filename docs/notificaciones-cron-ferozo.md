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
alerts.webCronEnabled = true
alerts.webCronToken = <SECRETO_ALEATORIO_DE_AL_MENOS_32_CARACTERES>
alerts.lockTimeoutSeconds = 900
```

Generar el secreto fuera del servidor, por ejemplo:

```bash
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

No reutilizar `MIGRATE_TOKEN` ni ninguna contraseña SMTP/DB.

## URL para la tarea programada de Ferozo

Configurar una tarea HTTP que invoque diariamente, inicialmente a las 07:00 hora de Argentina:

```text
https://vogelconsultoria.com.ar/mantenimiento/cron/notificaciones/<TOKEN>
```

El token forma parte de la URL porque el cron web del hosting puede no permitir headers personalizados. Debe tratarse como secreto: no compartir capturas del panel, rotarlo si se expone y no registrarlo en documentación, issues ni commits.

Con `alerts.webCronEnabled = false` la ruta responde 404. Con token incorrecto responde 401. Con token válido devuelve JSON con `overdue`, `collected` y `dispatched`.

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
4. Ejecutar el ciclo una vez y confirmar campana, email y push según preferencias.
5. Repetir la misma ejecución y confirmar ausencia de duplicados.
6. Forzar una falla temporal de canal y confirmar auditoría/reintento.
7. Recién después configurar la URL del cron web en Ferozo.

Nunca probar el primer disparo directamente contra producción.
