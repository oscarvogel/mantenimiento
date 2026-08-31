# Cron HTTP seguro para notificaciones — diseño

## Objetivo

Habilitar el dispatch programado en Ferozo sin SSH, shell, PHP CLI ni `php spark`, conservando una única implementación del ciclo de notificaciones y dejando Spark disponible para desarrollo y staging.

## Estado auditado

- `RunNotificationCycle` ya coordina detección preventiva, collector y dispatch; el comando y el controlador repiten el cableado de sus dependencias.
- `CodeIgniterEmailNotificationGateway` usa `Config\Email` y `service('email')`; sus valores continúan siendo configuración de entorno.
- El endpoint vigente es `GET /cron/notificaciones/<TOKEN>` y expone el token en la URL.
- `SystemNotificationClock` crea `DateTimeImmutable` sin timezone explícito, aunque `Config\App::$appTimezone` está definido.
- Lock, idempotencia, reintentos, entregas y ejecuciones persistidas pertenecen al caso de uso existente y no se duplicarán.

## Decisión

1. Agregar el servicio compartido `notificationCycle` al composition root. Spark y HTTP lo obtendrán mediante `service('notificationCycle')`.
2. Crear `POST /internal/cron/notifications/dispatch`.
3. Autenticar con `X-Cron-Token`; aceptar también `Authorization: Bearer` como alias de header, nunca query string.
4. Mantener `GET /cron/notificaciones/<TOKEN>` temporalmente como legacy/deprecated para no romper una tarea Ferozo desconocida. No será la ruta documentada para nuevas configuraciones.
5. Rechazar el método GET de la ruta nueva con `405` y `Allow: POST`. Token ausente devuelve `401`, token incorrecto devuelve `403`; endpoint deshabilitado continúa devolviendo `404` para no revelar su existencia.
6. Aplicar el `Throttler` nativo de CodeIgniter por IP hasheada, con límites configurables en `.env`; devolver `429` y `Retry-After` cuando corresponda.
7. Configurar `SystemNotificationClock` con `Config\App::$appTimezone`, manteniendo la inyección opcional de timezone para pruebas deterministas.
8. Reducir la respuesta HTTP a conteos técnicos: clave de ejecución horaria, vencimientos contados, eventos recolectados/creados/duplicados y contadores de dispatch. No incluir destinatarios, emails, payloads, credenciales, tokens ni excepciones.
9. Registrar intentos inválidos y fallas con mensajes no sensibles. El response de error será genérico y sin stack trace.

## Flujo

```text
POST + X-Cron-Token
  -> rate limiter por IP
  -> validación de configuración/token
  -> notificationCycle (único caso de uso)
  -> lock + ejecución + collector + entregas + trazabilidad
  -> resumen técnico redacted
```

La excepción de lock activo se traducirá a `409`; una falla inesperada a `500`. La idempotencia de la clave horaria y las transiciones `REINTENTO`/`ENVIADA` permanecen en sus adaptadores actuales.

## Alcance de infraestructura

No se habilitará producción ni se afirmará que Ferozo admite POST o headers hasta comprobarlo en su panel real. La documentación distinguirá el endpoint nuevo de la ruta legacy y dejará la configuración externa como gate.

## Validación

- Tests unitarios del reloj, rate limiter y contrato de composición.
- Tests de rutas y contrato HTTP para POST, GET/405, autenticación, redacción y legacy.
- Regresión de la suite de notificaciones existente.
- Staging remoto `fasa_189` mediante Docker/Coolify únicamente, preservando volúmenes y configuración; no se ejecutará ningún cambio productivo en esta rama.
