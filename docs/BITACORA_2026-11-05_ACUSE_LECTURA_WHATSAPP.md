# Bitácora: acuse de lectura por WhatsApp al chofer

**Fecha:** 05/11/2026
**Contexto:** Medición de uso (lecturas) + Notificaciones.
**Resultado:** implementado, desplegado en staging y producción, y verificado de punta a punta con una lectura real.

---

## 1. Problema

Los choferes no tenían forma de saber que la lectura había quedado registrada. El único acuse era la pantalla web de éxito, que pueden no ver porque cierran el navegador interno de WhatsApp o pierden la conexión. La empresa sí tiene la validación en el control de lecturas con evidencia fotográfica; el faltante era el cierre del circuito hacia el chofer.

## 2. Decisión

Enviar un WhatsApp de acuse al **chofer vigente del equipo** después de una lectura pública exitosa, con:

- empresa y equipo;
- kilometraje y/o horómetro confirmados;
- fecha y hora de la lectura;
- estado de la foto (IA confirmada, IA corregida, carga manual o revisión);
- comprobante `L-000731`.

El acuse es idempotente por lectura, se encola con prioridad alta y una falla del acuse nunca revierte la lectura. El formulario público es anónimo, por lo que el destinatario se resuelve en servidor a partir de la asignación vigente de chofer; no se usa el teléfono de quien carga.

## 3. Implementación

- Puerto `App\Application\Measurement\Port\ReadingReceiptNotifier`.
- Caso de uso `SendReadingReceipt`.
- Adaptador `CodeIgniterReadingReceiptNotifier`.
- Constructor de mensaje `ReadingReceiptMessageBuilder` (ES/PT).
- `PilotMessageHeader` compartido con el recordatorio semanal.
- `AiDiscrepancyPolicy` extraída a dominio para no duplicar umbrales.
- Disparo en `PublicEquipmentReadings::store()` después del commit, dentro de `try/catch`.
- Prioridad de `equipo.lectura_registrada` en `CodeIgniterWhatsAppNotificationDeliveryQueue::due()`.
- Comprobante visible también en la pantalla de éxito.
- Sin migraciones: se reutiliza `notificacion_whatsapp_entregas`.

Archivos runtime publicados en producción:

| Archivo | Acción |
|---|---|
| `app/Application/Notifications/ReadingReminderMessageBuilder.php` | modificado |
| `app/Config/Services.php` | modificado |
| `app/Controllers/PublicEquipmentReadings.php` | modificado |
| `app/Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php` | modificado |
| `app/Views/public_equipment/reading.php` | modificado |
| `app/Application/Measurement/Port/ReadingReceiptNotifier.php` | nuevo |
| `app/Application/Measurement/SendReadingReceipt.php` | nuevo |
| `app/Application/Notifications/PilotMessageHeader.php` | nuevo |
| `app/Application/Notifications/ReadingReceiptMessageBuilder.php` | nuevo |
| `app/Domain/Measurement/AiDiscrepancyPolicy.php` | nuevo |
| `app/Infrastructure/Notifications/CodeIgniterReadingReceiptNotifier.php` | nuevo |

## 4. Pruebas

- Suite completa: `1631` tests, `6533` aserciones, `0` fallos, `44` skipped preexistentes.
- Nuevas pruebas: política de discrepancia, builder ES/PT, caso de uso, adaptador con SQLite (idempotencia, piloto, omitidas, discrepancia, horas) y contrato de cableado/prioridad.

## 5. Despliegue en staging

- Host: `fasa_189`, Coolify, aplicación `mantenimiento-staging`.
- Commit desplegado: `eedc249f869bd591274edafc69b9cf32861bac60`.
- Deployment UUID: `o3ljo3htk0ctbubk9qgf3yzv`.
- Contenedor: `bdqictyu4q5xkfuh6aoh7sr8-20261105T140601`, `healthy`.
- `GET /login`: HTTP 200.

## 6. Despliegue quirúrgico en producción

- Destino: Ferozo, FTPS, `https://vogelconsultoria.com.ar/mantenimiento/`.
- Modo: quirúrgico, solo los 11 archivos runtime del acuse.
- Preflight: producción coincidía con la base `c1373fc660321773ba0df2be36a36bb115242c1a` en los 5 archivos a reemplazar y no existían los 6 nuevos.
- Subida: 11 archivos, 245.373 bytes.
- Verificación posterior por re-descarga: **11/11 hashes SHA-256 OK**.
- Sin migraciones, sin `vendor`, sin assets, sin `.env`.
- Backup, ledger y reporte: `.deploy/surgical-receipt-20261105-112007/` (local, no versionado).
- Estado local de producción actualizado a `eedc249f`.

Smoke test:

| Ruta | HTTP |
|---|---|
| `/mantenimiento/login` | 200 |
| `/mantenimiento/favicon.ico` | 200 |
| `/mantenimiento/.env` | 403 |
| `/mantenimiento/app/` | 403 |
| `/mantenimiento/vendor/` | 403 |
| `/mantenimiento/writable/` | 403 |

No se generó un log de errores nuevo durante la ventana del deploy.

## 7. Verificación funcional real

Lectura registrada por un usuario desde el enlace público:

| Dato | Valor |
|---|---|
| Lectura | `731` |
| Empresa | 4 (TSA) |
| Equipo | `HWW968` |
| Fecha de lectura | 05/11/2026 11:26:20 (America/Argentina/Buenos_Aires) |
| Kilometraje | 1.715.624 km |
| Chofer vigente | teléfono terminado en `9068` |
| Entrega WhatsApp | `6620` |
| Creación de la entrega | 05/11/2026 11:26:20 |
| Estado al momento de la auditoría | `PENDIENTE_CONFIRMACION` / gateway `processing` |
| Cron de envío | 05/11/2026 11:30:02–11:30:03 |
| Resultado del cron | `whatsapp_sent=1`, sin errores |

El WhatsApp llegó al chofer a las 11:30. La demora de ~4 minutos corresponde al ciclo de notificaciones cada 5 minutos y es el comportamiento esperado del envío encolado.

Mensaje recibido:

```text
TSA · Mantenimiento

✅ Lectura registrada

Hola MANUEL 👋

Equipo: HWW968
Kilometraje: 1.715.624 km
05/11/2026 11:26

La foto fue validada por IA.

Comprobante: L-000731

No necesitás hacer nada más.
```

El modo piloto estaba desactivado, por lo que el mensaje salió al chofer real del equipo. Esto confirma que el destinatario se resuelve por asignación vigente y no por el teléfono de quien carga la lectura.

## 8. Diagnóstico realizado

Se subió temporalmente una sonda PHP de solo lectura, protegida con `X-Migrate-Token` y limitada a producción, para consultar:

- configuración global de WhatsApp;
- empresas activas y habilitación del canal;
- últimas lecturas `QR_ANONIMO`;
- entrega `equipo.lectura_registrada` por lectura;
- chofer vigente y teléfono enmascarado;
- últimas entregas del recordatorio semanal;
- ejecuciones programadas y bloqueos.

La sonda se eliminó al terminar y se verificó HTTP 404. No se modificó ningún dato.

## 9. Observaciones y pendientes

- El acuse demora hasta 5 minutos por el cron. Si se necesita confirmación inmediata, se puede evaluar un envío sincrónico o una frecuencia menor, pero el encolado es más robusto.
- Si el chofer asignado no es quien carga la lectura, el acuse va al chofer asignado. Para pruebas con un teléfono propio, usar modo piloto o asignarse temporalmente como chofer.
- No se implementó un teléfono opcional para el uploader anónimo. Sería una decisión de producto aparte.
- Rollback quirúrgico: restaurar los 5 archivos respaldados y eliminar los 6 nuevos; el backup está en `.deploy/surgical-receipt-20261105-112007/backup/`.

## 10. Trazabilidad

- Commit: `4c4355e7` — `feat(lecturas): confirmar por WhatsApp la lectura registrada`.
- PR: #519.
- Merge en `main`: `eedc249f869bd591274edafc69b9cf32861bac60`.
- Spec: `docs/superpowers/specs/2026-11-05-acuse-lectura-whatsapp-design.md`.
- Contrato: `docs/CONTRATOS_ENTRE_CONTEXTOS.md` §4.8.
