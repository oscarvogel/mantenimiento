# Notificaciones por email y cron

## Configuración SMTP por entorno

El motor usa la configuración estándar de CodeIgniter `Config\Email` y el servicio `email`. Las credenciales deben vivir únicamente en el `.env` del entorno y nunca en el repositorio.

Verificar en staging/producción los valores efectivos equivalentes a:

```ini
email.fromEmail = "notificaciones@dominio"
email.fromName = "Mantenimiento"
email.protocol = "smtp"
email.SMTPHost = "smtp.ejemplo"
email.SMTPUser = "usuario"
email.SMTPPass = "secreto"
email.SMTPPort = 587
email.SMTPCrypto = "tls"
alerts.dailyRunTime = "07:00"
alerts.lockTimeoutSeconds = 900
```

La nomenclatura exacta puede variar según la configuración vigente de `Config\Email`; antes de activar el cron se debe validar con un envío real en staging.

## Ejecución manual

Desde la raíz de la aplicación:

```bash
php spark notifications:dispatch
```

Para una ejecución lógica identificable durante pruebas:

```bash
php spark notifications:dispatch staging-2026-08-25-0700
```

La clave de ejecución evita repetir el mismo despacho lógico ya completado.

## Cron recomendado

El comando puede ejecutarse cada 5 minutos; el propio motor decide qué entregas están vencidas para envío inmediato o para el resumen diario de las 07:00 y usa lock para evitar solapamientos.

Ejemplo Linux/cron:

```cron
*/5 * * * * cd /RUTA/APLICACION && /usr/bin/php spark notifications:dispatch >> writable/logs/notifications-cron.log 2>&1
```

En hosting donde PHP tenga otra ruta, reemplazar `/usr/bin/php` por la ruta real del binario CLI.

## Reglas de entrega

- `INMEDIATO`: se encola sin fecha futura y sale en la próxima corrida.
- `RESUMEN`: se programa para la próxima hora configurada en `alerts.dailyRunTime` (por defecto 07:00).
- `CRITICO`: sólo se agenda si la severidad es crítica y sale en la próxima corrida.
- `DESACTIVADO`: no crea entrega para ese canal.
- Los digests personales se separan por destinatario y sucursal.
- Los digests empresariales nunca mezclan empresas aunque compartan dirección de email.
- Una entrega exitosa pasa a `ENVIADA`; una falla transitoria pasa a `REINTENTO` con backoff y hasta 3 intentos controlados.
- La `clave_entrega` es única por evento/ciclo/destinatario/canal, evitando duplicados aunque la recolección se repita.
- El proceso usa lock y una clave de ejecución para evitar dos despachos simultáneos o repetir una corrida ya completada.

## Validación obligatoria en staging

Antes de habilitar producción:

1. Ejecutar migraciones pendientes.
2. Configurar SMTP real de staging.
3. Generar al menos un evento `RESUMEN` y uno `CRITICO`.
4. Ejecutar `php spark notifications:dispatch staging-email-smoke-1`.
5. Confirmar que el crítico se envió inmediatamente y que el resumen quedó programado para las 07:00 si todavía no corresponde.
6. Repetir exactamente la misma clave de ejecución y confirmar que informa `already_completed = 1` y no envía de nuevo.
7. Forzar temporalmente un SMTP inválido y verificar `REINTENTO`, `intentos`, `proximo_intento` y `ultimo_error`; restaurar luego la configuración.
8. Confirmar que un usuario con acceso a dos sucursales recibe digests separados por sucursal.
9. Confirmar en `writable/logs` que no existen errores de cron o bloqueo persistente.

No activar cron de producción hasta completar este smoke test en staging.