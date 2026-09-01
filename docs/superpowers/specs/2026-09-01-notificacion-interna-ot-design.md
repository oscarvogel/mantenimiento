# Diseño: notificación interna al rectificar una OT cerrada

## Contexto

Al corregir una lectura asociada a una orden de trabajo finalizada, el sistema actualiza la OT y encola el aviso empresarial por email, pero no publica una fila en `notificaciones`. Por eso el administrador no ve el cambio en la campana.

## Decisión

- El adaptador de mediciones sincroniza la OT y devuelve un `NotifiableEvent` cuando la OT queda rectificada.
- El caso de uso `CorrectReadingHandler` publica ese evento mediante un puerto de aplicación.
- El evento usa `orden.rectificada`, severidad `CRITICA`, alcance de la sucursal de la OT y una clave idempotente por OT y lectura de corrección.
- El publicador central resuelve todos los usuarios activos de la empresa con `ordenes.ver` y `notificaciones.ver`, respetando su alcance de sucursal. La preferencia interna permanece inmediata.
- El mismo publicador conserva el encolado del email empresarial, incorporando este tipo de evento a su matriz de emails.

## Fuera de alcance

- No se modifica la regla de corrección de kilometraje/horómetro.
- No se envían emails directamente durante la petición.
- No se cambia producción/Ferozo ni se alteran volúmenes de staging.

## Verificación

- Regresión de aplicación: el evento crítico devuelto por una sincronización de OT se entrega al puerto publicador.
- Suite PHPUnit completa.
- Revisión de alcance, idempotencia y severidad en los adaptadores existentes.
