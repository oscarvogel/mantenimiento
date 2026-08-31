# QA del motor de notificaciones

> **ENTORNO CANÓNICO DE STAGING: `fasa_189`. Mantenimiento staging corre exclusivamente en Docker/Coolify sobre `fasa_189`. No buscar ni operar `fasa_195` salvo instrucción explícita.**

```text
staging = fasa_189 / Docker / Coolify
producción = Ferozo / FTPS / sin CLI
```

Este documento cierra el gate técnico del issue #146 y define cómo validar el motor antes de considerar completo el issue padre #7.

## Contratos de persistencia

- `notificaciones` evita duplicados por `usuario_id + clave_evento`.
- `notificacion_entregas` evita duplicados por `clave_entrega`.
- `ejecuciones_programadas` evita repetir una ejecución lógica por `proceso + clave_ejecucion`.
- `bloqueos_proceso` usa `proceso` como clave primaria y token aleatorio con expiración.
- Cada entrega registra estado, intentos, próximo intento, fecha de envío y último error.
- Cada notificación conserva empresa, sucursal, usuario, tipo de evento, entidad origen y clave lógica.

## Smoke test de staging

Ambiente objetivo obligatorio: staging interno `fasa_189`, puerto `8090`.

1. Aplicar migraciones con el mecanismo normal del proyecto.
2. Ingresar con un usuario con `notificaciones.ver`.
3. Confirmar que la campana aparece y que `/notificaciones/resumen` responde sin 404/403.
4. Generar un evento controlado de preventivo próximo o vencido.
5. Ejecutar el ciclo. En Docker/staging puede usarse `php spark notifications:dispatch`; para validar el camino HTTP habilitar temporalmente `alerts.webCronEnabled` y probar `POST /internal/cron/notifications/dispatch` con `X-Cron-Token` (o `Authorization: Bearer`).
6. Verificar que la notificación aparece una sola vez en el centro interno.
7. Ejecutar nuevamente el mismo ciclo dentro de la misma clave horaria y confirmar que no duplica la notificación ni la entrega.
8. Marcar una notificación como leída y luego todas; confirmar que el contador se actualiza.
9. Con SMTP de staging habilitado, verificar entrega y trazabilidad de email.
10. Con Web Push habilitado, verificar suscripción, entrega y deep link.
11. Forzar una falla temporal de canal en un entorno controlado y comprobar `REINTENTO`, incremento de `intentos`, `proximo_intento` y `ultimo_error`.
12. Intentar lanzar dos dispatch simultáneos y comprobar que el segundo es rechazado mientras el lock esté vigente.
13. Probar el cron web deshabilitado (404), token ausente (401), token inválido (403), método GET (405), token válido (JSON técnico sin emails/tokens) y límite excedido (429).
14. No retirar aún `GET /cron/notificaciones/<TOKEN>`: verificar primero en el panel real de Ferozo si una tarea existente lo utiliza.

## Producción Ferozo

Producción no depende de `php spark`: configurar el mecanismo HTTP sólo después de verificar en el panel real de Ferozo si soporta POST y headers personalizados. El endpoint nuevo es `POST /internal/cron/notifications/dispatch` con secreto exclusivo en `.env`; `GET /cron/notificaciones/<TOKEN>` queda temporalmente deprecated. Activar `alerts.webCronEnabled = true` únicamente después del smoke de staging.

El comando CLI y el endpoint HTTP ejecutan el mismo caso de uso `RunNotificationCycle`; no existen reglas paralelas de recolección o despacho.

## Consultas de auditoría sugeridas

Revisar, sin modificar datos:

- duplicados lógicos en `notificaciones` por usuario y `clave_evento`;
- duplicados en `notificacion_entregas` por `clave_entrega`;
- entregas `FALLIDA` o `REINTENTO` con `ultimo_error` vacío;
- ejecuciones `EN_PROCESO` con lock vencido;
- notificaciones cuyo `empresa_id` no coincida con el usuario destinatario.

## Gate para cerrar #7

El issue padre #7 puede cerrarse cuando:

- #141 a #146 estén completados o exista un bloqueo explícito por módulo inexistente;
- CI esté verde;
- el smoke test de staging confirme campana, centro, email y push según los canales habilitados;
- el camino HTTP que usará Ferozo quede probado en staging;
- no aparezcan duplicados al repetir la misma ejecución lógica;
- los fallos de entrega queden trazados y reintentables.

## Rollback

Si el despliegue introduce una regresión:

1. deshabilitar `alerts.webCronEnabled` o detener la tarea del panel Ferozo;
2. volver al commit estable anterior de la aplicación;
3. no borrar tablas de notificaciones ni entregas si ya contienen auditoría real;
4. conservar `ejecuciones_programadas` y errores para diagnóstico;
5. reactivar el cron únicamente después de validar una ejecución manual sin duplicados.
