# Diseño: acuse de lectura por WhatsApp al chofer

**Fecha:** 05/11/2026
**Estado:** implementado y verificado en staging y producción (05/11/2026). Evidencia en `docs/BITACORA_2026-11-05_ACUSE_LECTURA_WHATSAPP.md`.
**Contexto:** Medición de uso (lecturas) + Notificaciones.

## Intención

Cerrar el circuito de carga de kilometraje: cuando el chofer registra una lectura desde el enlace público, recibir un WhatsApp que confirme que la lectura quedó guardada, con el valor aceptado y un comprobante. Hoy el único acuse es la pantalla web de éxito, que el chofer puede no ver porque cierra el navegador interno de WhatsApp, pierde la conexión o no interpreta el mensaje.

La empresa ya tiene su propia validación en el control de lecturas con evidencia fotográfica. Este acuse es para el chofer, no para la empresa.

## Problema observado

- El recordatorio sale al chofer y la lectura se guarda, pero no vuelve ninguna confirmación por WhatsApp.
- La pantalla `public_equipment/reading.php:40` muestra "Lectura registrada", pero no el kilometraje guardado ni un comprobante.
- Si el envío del recordatorio o la carga fallan, el chofer no tiene forma de distinguir "no se envió", "no se guardó" o "se guardó y no me enteré".

## Alcance

- Enviar un WhatsApp de acuse al chofer vigente del equipo después de una lectura pública exitosa.
- El mensaje incluye: empresa, equipo, kilometraje y/o horómetro confirmados, fecha/hora, estado de la foto (IA confirmada, IA corregida, manual o revisión) y un comprobante.
- El acuse es idempotente por lectura: una lectura produce como máximo un mensaje.
- El fallo del acuse nunca revierte ni invalida la lectura.
- La pantalla de éxito muestra el mismo comprobante y el valor guardado, como respaldo si el WhatsApp no llega.

## Fuera de alcance

- Acuses para rechazos de lectura (foto inválida, kilometraje menor, salto no confirmado). Esos errores se siguen mostrando en la pantalla.
- Acuses para cargas autenticadas (`MaintenanceCircuit`, `QuickReadings`, correcciones de oficina). Solo dispara el flujo público `QR_ANONIMO`, que es el que usa el chofer.
- Notificar a la empresa: ya tiene el control de lecturas con evidencia.
- Aprobación humana formal del kilometraje. No existe hoy y el mensaje no debe decir "aprobado".
- Respuestas entrantes del chofer, comprobante en PDF o página pública de verificación.
- Cambios de esquema: se reutiliza `notificacion_whatsapp_entregas`.

## Decisiones de implementación

1. **Envío encolado, no sincrónico.** El acuse se inserta en `notificacion_whatsapp_entregas` como `PENDIENTE` y lo despacha el ciclo existente. Evita bloquear el POST del chofer, reutiliza reintentos, backoff, reconciliación, modo piloto y trazabilidad. El cron corre cada 5 minutos, por lo que la demora esperada es de minutos; la pantalla de éxito cubre ese intervalo.
2. **Prioridad alta en la cola.** `due()` debe devolver primero `equipo.lectura_registrada`, antes de vencimientos y recordatorios, para que un lote grande no demore el acuse.
3. **Destinatario resuelto en servidor.** Chofer vigente del equipo (`employee_equipment_assignments`, rol `CHOFER`, `fecha_hasta IS NULL`), mismo criterio que `ClaimReadingReminder` y el recordatorio semanal. El navegador nunca envía un teléfono.
4. **Modo piloto respetado.** Si `whatsapp_pilot_enabled` está activo, el mensaje sale al teléfono piloto y lleva el encabezado de prueba controlada, igual que el recordatorio.
5. **Sin migración.** La tabla ya admite el nuevo `tipo_evento` y la `clave_entrega` única.
6. **Best-effort.** El acuse se dispara después del commit, dentro de `try/catch`. Una excepción se registra y se ignora; la lectura ya está persistida.
7. **Lenguaje.** No usar "aprobado". Usar "registrada", "confirmada" o "queda en revisión" según corresponda.
8. **Horas y kilómetros.** Si la lectura trae ambos valores, el mensaje muestra ambos. Si solo trae uno, muestra el que exista.

## Arquitectura

La dirección de dependencias se mantiene: Presentación → Aplicación → Dominio. El controlador no habla con el gateway ni con la tabla de entregas.

### Componentes nuevos

1. `app/Application/Measurement/Port/ReadingReceiptNotifier.php`

   Puerto del consumidor (Medición). No conoce WhatsApp ni CodeIgniter.

   ```php
   public function notify(int $companyId, int $equipmentId, int $readingId): void;
   ```

2. `app/Application/Measurement/SendReadingReceipt.php`

   Caso de uso mínimo. Recibe el puerto, captura cualquier error y lo registra. No propaga excepciones.

   ```php
   public function execute(int $companyId, int $equipmentId, int $readingId): void;
   ```

3. `app/Application/Notifications/ReadingReceiptMessageBuilder.php`

   Constructor puro del texto, con locale `ES`/`PT`. No consulta base.

   ```php
   public function build(
       string $locale,
       string $companyName,
       string $driverFullName,
       string $equipmentLabel,
       ?int $kilometers,
       ?float $hours,
       string $method,
       ?int $detectedKm,
       bool $needsReview,
       \DateTimeImmutable $registeredAt,
       int $readingId,
       bool $pilotEnabled,
       bool $realPhoneValid,
   ): string;
   ```

4. `app/Application/Notifications/PilotMessageHeader.php`

   Extrae el encabezado de prueba controlada que hoy vive en `ReadingReminderMessageBuilder::pilotHeader()` y lo reutiliza en ambos builders. Si la extracción obliga a tocar demasiadas pruebas, se permite duplicar el texto en el nuevo builder, pero no cambiar el del recordatorio.

5. `app/Domain/Measurement/AiDiscrepancyPolicy.php`

   Regla pura para decidir si una lectura requiere revisión. Mueve los umbrales que hoy están en `PublicEquipmentReadings` (`AI_DISCREPANCY_MIN_CONFIDENCE = 0.80`, `AI_DISCREPANCY_MIN_KM = 1000`, `AI_DISCREPANCY_MIN_RATIO = 0.05`) a dominio, para no duplicarlos entre la alerta y el acuse.

   ```php
   public function needsReview(?int $detectedKm, ?int $confirmedKm, ?float $confidence): bool;
   ```

6. `app/Infrastructure/Notifications/CodeIgniterReadingReceiptNotifier.php`

   Adaptador del puerto. Resuelve lectura, empresa, equipo, sucursal, chofer, teléfono, idioma, piloto e instancia; arma el mensaje e inserta la entrega.

   ```php
   public function __construct(
       \CodeIgniter\Database\BaseConnection $database,
       \App\Application\Notifications\Port\WhatsAppNotificationGateway $gateway,
       \App\Application\Notifications\Port\GlobalNotificationSettingsStore $settings,
       \App\Application\Notifications\Port\NotificationClock $clock,
   ) {}
   ```

### Componentes modificados

1. `app/Controllers/PublicEquipmentReadings.php`
   - Después de `transCommit()` (línea 460) y del bloque de discrepancia, llamar a `service('sendReadingReceipt')->execute($companyId, $equipmentId, $readingId)`.
   - Guardar en flashdata `reading_receipt` el kilometraje, horómetro y comprobante para la pantalla de éxito.
   - Usar `AiDiscrepancyPolicy` en lugar de las constantes locales.

2. `app/Views/public_equipment/reading.php`
   - En el bloque `registered`, mostrar el valor guardado y el comprobante (`L-000123`).
   - Si no hay flashdata (por ejemplo, refresh), mantener el texto actual.

3. `app/Config/Services.php`
   - Registrar `readingReceiptNotifier` y `sendReadingReceipt`, siguiendo el patrón de `claimReadingReminder`.

4. `app/Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php`
   - En `due()`, agregar antes del orden actual:
     ```php
     ->orderBy("tipo_evento = 'equipo.lectura_registrada'", 'DESC', false)
     ```
   - El resto de la lógica de despacho no cambia: cualquier tipo distinto de `equipo.recordatorio_lectura_semanal` ya se despacha sin la validación de chofer vigente.

## Flujo

```text
Chofer abre el enlace público
  -> sube foto
  -> IA analiza (o carga manual)
  -> POST store()
  -> transacción: lectura + evidencia + equipo + auditoría
  -> commit
  -> SendReadingReceipt (best-effort)
       -> CodeIgniterReadingReceiptNotifier
            -> resuelve chofer vigente y teléfono
            -> arma mensaje
            -> inserta notificacion_whatsapp_entregas (PENDIENTE)
  -> redirect ?registrada=1 con comprobante en flashdata
  -> cron notifications:dispatch
       -> due() prioriza el acuse
       -> gateway envía
       -> accepted() / failed() / reconcileStatus()
```

## Mensaje

Español:

```text
*{Empresa}* · Mantenimiento

✅ *Lectura registrada*

Hola {Nombre} 👋

Equipo: *{codigo} · {patente}*
Kilometraje: *{km} km*
{Fecha y hora}

{Estado de la foto}

Comprobante: *{L-000123}*

No necesitás hacer nada más.
```

Portugués: mismo contenido traducido, como en `ReadingReminderMessageBuilder`.

Variantes de `{Estado de la foto}`:

| Situación | Texto |
|---|---|
| `FOTO_IA_CONFIRMADA` | La foto fue validada por IA. |
| `FOTO_IA_CORREGIDA` | La IA había leído {detectado} km; quedó registrado el valor que confirmaste. |
| `FOTO_MANUAL` | Registramos el valor que ingresaste. |
| `needsReview = true` | La foto y el valor cargado no coinciden; mantenimiento lo va a revisar. |

Reglas de formato:

- Kilometraje: `number_format($km, 0, ',', '.')` en ES; separador local en PT.
- Horómetro: un decimal con coma en ES.
- Comprobante: `L-` + `str_pad((string) $readingId, 6, '0', STR_PAD_LEFT)`.
- Si no hay nombre de chofer: "Hola 👋".
- Sin enlace en el mensaje: el acuse no invita a volver a cargar.

## Idempotencia y estados

- `tipo_evento`: `equipo.lectura_registrada`.
- `clave_entrega`: `lectura.registrada:empresa:{companyId}:equipo:{equipmentId}:lectura:{readingId}`.
- `external_ref`: `mantenimiento:` + `clave_entrega`.
- Inserción con `ignore(true)` sobre el índice único de `clave_entrega`. Si `affectedRows() === 0`, no se hace nada.
- Estado inicial `PENDIENTE` con `proximo_intento = NULL` para despacho inmediato en el próximo ciclo.
- Sin teléfono válido, sin chofer, piloto activo sin teléfono piloto o empresa con WhatsApp deshabilitado: se inserta `OMITIDA` con `ultimo_error` explicativo, salvo que la empresa tenga el canal deshabilitado, caso en que no se inserta nada.
- Una lectura anulada o corregida después no genera un segundo acuse. El comprobante original queda como evidencia de lo registrado en su momento.

## Casos borde

- **Lectura duplicada:** el `request_key` y la guarda de 120 segundos ya impiden una segunda lectura; el acuse también es idempotente por `readingId`.
- **Chofer cambiado entre la carga y el despacho:** el acuse va al teléfono resuelto al momento de la carga. Es correcto: confirma quién tenía la unidad cuando se registró.
- **Chofer sin WhatsApp:** se registra `OMITIDA`; la pantalla de éxito sigue mostrando el comprobante. El aviso semanal de teléfonos faltantes ya existe.
- **Falla del gateway:** el ciclo reintenta con backoff. La lectura no se toca.
- **Modo piloto:** el texto lleva el encabezado de prueba y sale al teléfono piloto. No se contacta al chofer real.
- **Empresa con WhatsApp deshabilitado:** no se encola ni se inserta nada.
- **Equipo sin `controla_km` pero con horómetro:** el acuse se genera igual y muestra solo horas.
- **Lectura de más de 999999:** el comprobante no se trunca; el padding es mínimo.

## Pruebas

- `tests/unit/Domain/Measurement/AiDiscrepancyPolicyTest.php`: umbrales, confianza baja, valores nulos, diferencia justo en el límite.
- `tests/unit/Application/Notifications/ReadingReceiptMessageBuilderTest.php`: ES/PT, km solo, horas solo, ambos, IA confirmada, IA corregida, manual, revisión, piloto, nombre vacío, formato de miles.
- `tests/unit/Application/Measurement/SendReadingReceiptTest.php`: llama al puerto una vez; si el puerto lanza, no propaga y registra.
- `tests/unit/Infrastructure/Notifications/CodeIgniterReadingReceiptNotifierTest.php` (con base real, como el resto de adaptadores): resuelve chofer y teléfono; piloto; sin teléfono → `OMITIDA`; empresa deshabilitada → sin fila; duplicado → una sola fila; instancia correcta; `clave_entrega` esperada.
- `tests/unit/Infrastructure/Notifications/ReadingReceiptQueuePriorityTest.php`: `due()` devuelve el acuse antes que un recordatorio y antes que una alerta.
- Prueba de contrato HTTP/feature: un POST público exitoso encola exactamente un acuse; un POST rechazado no encola nada; una falla del acuse no revierte la lectura; refresh del éxito no duplica.
- Regresión: el texto del recordatorio semanal no cambia si se extrae `PilotMessageHeader`.

## Verificación en staging

Entorno canónico: `fasa_189` (Coolify/Docker), nunca producción.

1. Activar modo piloto con un teléfono de prueba.
2. Abrir el enlace público de un equipo con `controla_km = 1`, subir una foto válida y registrar.
3. Confirmar en la pantalla de éxito el km y el comprobante.
4. Ejecutar el ciclo de notificaciones y verificar el WhatsApp en el teléfono piloto.
5. Revisar `notificacion_whatsapp_entregas`: `tipo_evento = equipo.lectura_registrada`, estado `ACEPTADA`, `enviada_en` con hora, mensaje con el km correcto.
6. Refrescar la pantalla y repetir el POST: no debe aparecer una segunda fila.
7. Probar un equipo sin chofer o con teléfono inválido: fila `OMITIDA` con motivo.
8. Desactivar WhatsApp de la empresa: no debe generarse fila.

## Riesgos y mitigaciones

- **Ruido si el chofer carga lecturas fuera del flujo semanal.** Se acepta: el acuse es la confirmación de una acción que él mismo hizo. Si molesta, se puede agregar un flag por empresa en una segunda iteración.
- **Demora del cron.** Mitigada por la prioridad en `due()` y por la pantalla de éxito con comprobante.
- **Mensaje engañoso si la lectura se revisa después.** Se evita no usando "aprobado" y agregando la variante de revisión.
- **Exposición de IDs internos.** El comprobante `L-{id}` es una referencia de atención, no una URL ni una credencial.

## Orden de implementación

1. `AiDiscrepancyPolicy` + pruebas + reemplazo en el controlador.
2. `ReadingReceiptMessageBuilder` + `PilotMessageHeader` + pruebas.
3. Puerto, caso de uso y adaptador + pruebas.
4. Cableado en `Services` y llamada post-commit en el controlador.
5. Comprobante en la pantalla de éxito.
6. Prioridad en `due()`.
7. Pruebas de contrato y verificación en `fasa_189`.
