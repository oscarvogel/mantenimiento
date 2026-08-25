# Web Push / PWA — operación y validación

## Configuración por entorno

Web Push debe configurarse exclusivamente en `.env`. Las claves reales VAPID no se versionan.

```ini
webpush.enabled = true
webpush.vapidPublicKey = <PUBLIC_KEY>
webpush.vapidPrivateKey = <PRIVATE_KEY>
webpush.subject = 'mailto:alertas@example.com'
```

Requisitos:

- producción y staging accesibles por HTTPS;
- `webpush.subject` debe ser una URL válida o `mailto:`;
- la clave pública es enviada al navegador; la clave privada queda sólo en servidor;
- `.env` permanece fuera de Git.

## Flujo esperado

1. El usuario entra a `Notificaciones > Dispositivos`.
2. Pulsa `Activar en este dispositivo`.
3. El navegador solicita permiso sólo por esa acción explícita.
4. Se registra `service-worker.js` dentro del scope real de la aplicación.
5. El navegador genera la suscripción Push y el backend la persiste asociada al usuario.
6. `Enviar push de prueba` usa el mismo gateway que el despacho operativo.
7. Un click sobre la notificación abre el deep link solamente si pertenece al mismo origen y al scope de la aplicación.
8. Si ya existe una ventana de la aplicación, el service worker la navega al destino y la enfoca; evita abrir pestañas innecesarias.
9. `Desactivar este dispositivo` desactiva la suscripción en backend y luego la elimina del navegador.

## Endpoints inválidos y fallos temporales

- una suscripción expirada se marca inválida y se desactiva;
- un fallo temporal se registra en la suscripción;
- si todos los dispositivos activos fallan temporalmente, la entrega queda en reintento controlado;
- si el usuario no tiene dispositivos activos, la entrega se marca omitida sin romper el despacho general;
- una suscripción inválida nunca detiene la entrega hacia otros usuarios/dispositivos.

## Smoke test obligatorio en staging

Usar el entorno de prueba autorizado y no producción.

1. Configurar VAPID en el `.env` de staging y habilitar `webpush.enabled = true`.
2. Abrir el sistema por HTTPS con un usuario de empresa que tenga `notificaciones.ver`.
3. Ir a `Notificaciones > Dispositivos`.
4. Activar Push y aceptar el permiso.
5. Confirmar que la UI indica `Activo en este dispositivo`.
6. Ejecutar `Enviar push de prueba` y confirmar recepción real.
7. Hacer click en la notificación y verificar que abre `/notificaciones` dentro de la misma instalación.
8. Generar un evento operativo con Push habilitado y ejecutar:

```bash
php spark notifications:dispatch
```

9. Confirmar una sola entrega para ese evento/ciclo.
10. Probar desactivación desde la UI y confirmar que un nuevo test ya no se entrega a ese dispositivo.
11. Probar en un navegador sin soporte o con permiso bloqueado y verificar que la UI informa el estado sin romper la página.

## Compatibilidad

La funcionalidad depende del soporte Web Push del navegador y de un contexto seguro. En navegadores/dispositivos sin soporte, el centro interno y el email continúan funcionando normalmente.
