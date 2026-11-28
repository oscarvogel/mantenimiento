# Auditoría — Recordatorios de kilómetros a choferes desvinculados

**Fecha de la auditoría:** 27/11/2026
**Incidente:** camión siniestrado / en taller; el chofer desvinculado de la unidad sigue recibiendo recordatorios de carga de kilómetros por WhatsApp.
**Último mensaje observado:** 27/11/2026 08:00 (hora Argentina).
**Alcance:** auditoría de solo lectura sobre producción (Ferozo) y revisión del código del circuito de notificaciones.
**Restricciones respetadas:** no se modificó código de la aplicación, base de datos, asignaciones, colas, cron ni configuración de producción. No se desplegó. No se crearon ni cerraron issues.

---

## 0. Resumen ejecutivo

1. **El mensaje de las 08:00 existió y salió a la línea del chofer.** No es una falsa alarma del usuario: la entrega `notificacion_whatsapp_entregas.id = 7373` se generó el 27/11/2026 a las 08:00:05, se despachó en el ciclo de las 08:00 y el gateway la confirmó como leída (`read`) a las 08:05:07. El texto dice: *“Hola WALTER 👋 Todavía falta que informes los kilómetros de esta semana del vehículo **NEB021**”*.

2. **La causa raíz demostrada no es una cola vieja ni un error del despachador: es que la desvinculación nunca quedó registrada.** Al momento del envío —y todavía hoy— la asignación `employee_equipment_assignments.id = 97` (empresa 4 / equipo 43 NEB021 / empleado 4) tiene `fecha_hasta = NULL`. El chofer está activo (`empleados.activo = 1`, sin fecha de baja). El circuito lo consideró, correctamente según los datos, el chofer vigente de NEB021.

3. **El producto no ofrece una operación de “desvincular chofer de la unidad”.** La ficha del equipo solo permite “Asignar chofer” o “Cambiar chofer” con un empleado **obligatorio** (`<select required>`), y el controlador rechaza `empleado_id <= 0`. La única forma de cerrar una asignación sin reemplazo es dar de baja al empleado. Si el administrador intentó desvincular sin reemplazo, la acción no tenía un camino válido para persistirse.

4. **No existe auditoría de cambios de asignación.** La tabla `auditoria` no tiene registros de asignaciones; `updated_by` en la asignación solo guarda el último usuario. Por eso **no se puede demostrar** cuándo ni cómo intentó desvincular el administrador: el sistema no lo registró.

5. **La guarda de despacho existe y está desplegada, pero es más débil que el criterio de generación.** `isCurrentDriverAssignment()` se ejecuta antes de enviar, pero solo comprueba que exista una fila abierta (`fecha_hasta IS NULL`). No vuelve a comprobar empleado activo, empleado no borrado, equipo activo, tipo que controla km, empresa activa ni WhatsApp habilitado. Tampoco se aplica a otros tipos de mensaje al chofer (vencimientos, preventivos, acuse de lectura).

6. **No hay fugas vigentes en la cola.** Al 27/11/2026: 0 pendientes/reintentos a choferes sin asignación vigente, 0 recordatorios a equipos no activos y 0 pendientes a empleados inactivos. Los únicos 4 envíos efectivos fuera de todo intervalo de asignación son del **22/09/2026** (previos a los hotfixes #365/#449), sobre asignaciones con fechas inválidas heredadas de la importación; hoy esos empleados figuran inactivos con baja retroactiva.

7. **Hay dos brechas adicionales que no causaron este incidente pero pueden reproducir el síntoma:**
   - la baja de empleado no es transaccional (dos escrituras separadas: empleado y asignación);
   - las unidades “en taller / fuera de servicio” no participan del criterio de recordatorios. Hay 41 recordatorios enviados a 6 equipos con OT abierta y la bandera `ordenes_trabajo.equipo_fuera_servicio` nunca se usó (`0` casos).

---

## 1. Método, evidencia y límites

### 1.1 Cómo se obtuvo la evidencia

- Extracción **exclusivamente de lectura** en producción, dentro de `START TRANSACTION READ ONLY` (confirmado por el propio informe remoto: `transaccion_solo_lectura = true`).
- Se usó el patrón ya documentado en `docs/BITACORA_2026-11-26_AUDITORIA_COTIZACIONES_VIAJES.md`: script PHP temporal con nombre aleatorio, token efímero de alta entropía en la cabecera `X-Audit-Token`, borrado en `finally` y verificación de ausencia por FTPS y HTTP.
- Se ejecutaron 5 pasadas (contexto, intervalos de asignación, unidades fuera de servicio, auditoría de cambios y órdenes históricas). En las 5 el archivo remoto quedó borrado y la URL devolvió **404**.
- Se descargaron (RETR, sin subir nada de la aplicación) 5 archivos de código de producción para comparar drift.
- El único artefacto escrito transitoriamente en el webroot fue el script de auditoría (nombre aleatorio, borrado y verificado 404). No se tocó ningún archivo de la aplicación, tabla, cola, cron ni configuración.

### 1.2 Drift de código

Los 5 archivos auditados son **idénticos** al checkout local (`igual_texto = true`), incluido el que contiene la guarda:

| Archivo | SHA-256 normalizado | Guarda desplegada |
|---|---|---|
| `app/Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php` | `67b0abf6…bd39cd` | sí (`isCurrentDriverAssignment`, `Chofer ya no asignado`) |
| `app/Application/Notifications/RunNotificationDispatch.php` | `2bf27d4b…4d5b32` | — |
| `app/Application/Employees/EmployeeService.php` | `73c88b8a…a872cd` | — |
| `app/Infrastructure/Employees/CodeIgniterEmployeeAssignmentRepository.php` | `bcfcc582…0b77` | — |
| `app/Infrastructure/Notifications/CodeIgniterReadingReceiptNotifier.php` | `8e645df8…d48d2d` | — |

**Conclusión:** no estamos ante un caso de “el fix está en el repo pero no en producción”. La guarda de #449 está desplegada y se ejecutó.

### 1.3 Límites de la evidencia

- No se puede demostrar la **intención** ni el **momento exacto** del intento de desvinculación: no existe registro de auditoría de asignaciones y la fila 97 nunca se modificó (`updated_at = 2026-10-07 08:32:55`).
- No se puede afirmar cuál de las dos unidades sufrió el vuelco: el sistema no registra el siniestro. La unidad que dejó de registrar lecturas es NEB021 (última 09/11/2026); OJG526 siguió registrando (11/11, 16/11 y 27/11). Es **consistente** con que el camión siniestrado sea NEB021, pero es una inferencia, no un hecho probado.
- El detalle de producción se guardó en `tmp/` (ignorado por Git) y el teléfono se enmascara en este documento.

---

## 2. Identificación del caso

### 2.1 Chofer

| Dato | Valor |
|---|---|
| Empresa | 4 — TSA |
| Empleado | `id = 4` — WALTER BROEMSER |
| Teléfono | `5493******939` (coincide con el denunciado, +54 9 3755 54-59…) |
| Estado | `activo = 1`, `fecha_baja = NULL`, `deleted_at = NULL` |
| Última modificación del legajo | 2026-09-23 16:47:35 |

### 2.2 Asignaciones del chofer

| Asignación | Equipo | Desde | Hasta | Creada / actualizada | Estado |
|---|---|---|---|---|---|
| `id = 4` | 7 — OJG526 | 2026-09-10 | **2026-10-07** | 2026-10-07 08:32:55 | cerrada |
| `id = 97` | **43 — NEB021** | **2026-10-07** | **NULL** | 2026-10-07 08:32:55 | **abierta / vigente** |

La única baja registrada del chofer es la salida de OJG526 el 07/10/2026. **No existe ninguna fila que registre su salida de NEB021.** La asignación 97 no se volvió a tocar: `updated_at` es el mismo del alta.

### 2.3 Unidades

| Equipo | Código/Patente | Estado | Tipo | Controla km | Última lectura | Observación |
|---|---|---|---|---|---|---|
| 43 | **NEB021** | ACTIVO | Camión | sí | 2026-11-09 11:06:48 (id 788, 1.045.351 km) | 17 días sin lectura al 27/11 |
| 7 | OJG526 | ACTIVO | Camión | sí | 2026-11-27 08:09:01 (id 971, 1.225.290 km) | sigue operando |

- **No hay ninguna orden de trabajo abierta** para los equipos 7 ni 43. Las 5 OT históricas de esas unidades están `FINALIZADA` (la última de NEB021 es del 25/09/2026 y la de OJG526 del 26/10/2026). Ninguna registra el siniestro de noviembre. El sistema no tiene registro de que NEB021 esté en taller.
- `equipos.estado` solo admite `ACTIVO` / `BAJA` (`app/Domain/Assets/Equipment.php:13-14`). No existe un estado “en taller” o “fuera de servicio” a nivel equipo.
- El chofer actual de OJG526 es el empleado 98 (ANIBAL MELA), asignación 116 desde 2026-11-11 17:46:49.

---

## 3. Cronología del incidente

| Momento (AR) | Hecho | Fuente |
|---|---|---|
| 2026-10-07 08:32:55 | El administrador (usuario 10) cierra la asignación 4 (OJG526) y crea la 97 (NEB021) para el empleado 4. | `employee_equipment_assignments` |
| 2026-11-09 11:06:48 | Última lectura de NEB021 (1.045.351 km), origen `QR_ANONIMO`. Se encola y envía el acuse al chofer 4. | `lecturas_equipo` id 788; entrega 6788 |
| 2026-11-23 08:00:03 | Recordatorio semanal (etapa inicial, semana W48) a NEB021/chofer 4; enviado 08:10:04. | entrega 7148 |
| 2026-11-25 08:00:03 | Seguimiento de miércoles a NEB021/chofer 4; enviado 08:10:03. | entrega 7286 |
| **2026-11-27 08:00:02** | Arranca el ciclo del cron (`notificaciones:ciclo`, clave `2026-11-27-08-00`): vencimientos, recolección, informes, despacho. | `automatizacion_etapas` |
| **2026-11-27 08:00:05** | `scheduleWeeklyReadingReminders()` genera la etapa `seguimiento_lectura_viernes` para NEB021/chofer 4 y crea la entrega **7373** (`PENDIENTE`). | `notificacion_whatsapp_entregas` 7373; `notificaciones` 5661092/5661095 |
| **2026-11-27 08:00:05** | Se crea el aviso interno a los responsables (usuarios 10 y 19): “WALTER BROEMSER no registró el kilometraje semanal de NEB021…”. | `notificaciones` id 5661092, 5661095 |
| **2026-11-27 08:00:02–08:00:16** | El despacho envía 4 mensajes WhatsApp (`whatsapp_sent = 4`), entre ellos el 7373. El gateway responde “encolado”. | `automatizacion_etapas` 08:00 |
| **2026-11-27 08:05:07** | La reconciliación del ciclo 08:05 confirma el mensaje como **`read`** y fija `enviada_en = 2026-11-27 08:05:07`. | entrega 7373; etapa 08:05 (`whatsapp_confirmed = 4`) |
| 2026-11-27 08:09:01 | Se registra una lectura en OJG526 (equipo 7), que genera un acuse **al chofer actual 98**, no al 4. | `lecturas_equipo` 971; entrega 7376 |

**Lectura de la cronología:** el mensaje se generó, encoló y envió en el mismo ciclo de las 08:00, a partir de una asignación que seguía abierta. No hubo demora de cola ni reintento de un mensaje viejo.

---

## 4. Causa raíz demostrada

### 4.1 Hecho central

Al 27/11/2026 08:00:05 —y al cierre de esta auditoría— la base de producción dice:

```
employee_equipment_assignments.id = 97
empresa_id = 4, equipo_id = 43 (NEB021), empleado_id = 4
rol = CHOFER, fecha_desde = 2026-10-07, fecha_hasta = NULL
created_at = updated_at = 2026-10-07 08:32:55, updated_by = 10
```

`empleados.id = 4`: `activo = 1`, `fecha_baja = NULL`, `deleted_at = NULL`.

Por lo tanto, el circuito **no envió a un chofer desvinculado**: envió al chofer que la base seguía considerando vigente. La condición de elegibilidad se cumplía.

### 4.2 Por qué la desvinculación no quedó registrada

La causa es de **producto y de proceso**, no del motor de recordatorios:

1. **No existe la operación “desvincular chofer”.** La ficha del equipo (`frontend/src/pages/operations/EquipmentDetailPage.vue:155-173`) solo tiene “Asignar chofer” / “Cambiar chofer”; el `<select name="empleado_id">` es `required` (línea 158) y el controlador rechaza un id vacío con “Seleccioná un chofer válido” (`app/Controllers/EquipmentManagement.php:156-158`). No hay botón, ruta ni caso de uso para cerrar la asignación sin reemplazo.
2. **Las únicas formas de cerrar una asignación son reasignar o dar de baja al empleado.** `assignDriver()` cierra la fila vigente del equipo y la del empleado (`CodeIgniterEmployeeAssignmentRepository.php:159-184`); `closeCurrentForEmployee()` se invoca solo desde `terminate()` (`EmployeeService.php:110-124`). Ninguna de las dos es “desvincular de esta unidad”.
3. **No hay auditoría de asignaciones.** La tabla `auditoria` tiene exactamente 1 fila en producción (un cambio de usuario del 17/11/2026), y `driver_assignment_import_audit` solo registra la importación inicial. No hay rastro de un intento de desvinculación. El sistema no puede demostrar que la acción se intentó, ni el usuario puede verificarla.
4. **La ficha no advierte el efecto.** Si el administrador elige otro chofer, la pantalla no explica que el anterior deja de recibir recordatorios ni que, si no se elige reemplazo, no hay forma de cortarlos.

**Conclusión:** la desvinculación no se persistió porque el dominio no la representa. El recordatorio fue una consecuencia correcta de un estado de datos incorrecto/incompleto, no un fallo del envío.

### 4.3 Qué NO es la causa (hipótesis descartadas con evidencia)

| Hipótesis | Evidencia que la descarta |
|---|---|
| El PR #471 rompió la elegibilidad | #471 (`c0d69f68`, 08/10/2026) solo unificó el generador de mensajes. La consulta de elegibilidad y la guarda no cambiaron. La guarda de #449 es anterior (`719d68e2`, 07/10/2026) y sigue presente en producción. |
| La cola tenía un mensaje viejo reintentándose | La entrega 7373 se creó el 27/11 a las 08:00:05 y se envió en el mismo ciclo. `intentos = 0`, sin `proximo_intento`. |
| El cron tomó un destinatario de una asignación histórica | La consulta de generación parte de `employee_equipment_assignments` con `fecha_hasta IS NULL` y `emp.activo = 1` (líneas 707-731). No lee historial. |
| Hay asignaciones duplicadas y la guarda se conforma con cualquiera | 0 equipos con más de una asignación abierta; 0 empleados con más de una asignación abierta. |
| El mensaje salió a un teléfono viejo o de otra empresa | El teléfono de la entrega coincide con el del empleado 4 (`5493******939`); empresa 4 en todos los registros. |
| El modo piloto desvió el mensaje | `configuracion_canales_globales.whatsapp_pilot_enabled = 0`; el mensaje fue al teléfono real del chofer y el gateway lo marcó `read`. |

### 4.4 Lo que no se puede afirmar

- No se puede afirmar **cuándo** intentó desvincular el administrador ni si completó el formulario: no hay auditoría.
- No se puede afirmar que NEB021 sea el camión siniestrado: no hay OT ni estado de siniestro. Es consistente con la ausencia de lecturas desde el 09/11, pero no está probado.
- No se puede afirmar que el administrador haya usado “Cambiar chofer”: si lo hubiera hecho con otro empleado, la asignación 97 estaría cerrada y existiría otra abierta. No ocurrió.

### 4.5 Las dos lecturas posibles (la causa raíz es la misma)

| Si el camión siniestrado es… | Qué muestran los datos | Qué hay que corregir |
|---|---|---|
| **NEB021** (equipo 43) | La desvinculación de NEB021 no se registró: la asignación 97 sigue abierta. El mensaje de las 08:00 es de NEB021 y salió al chofer que la base considera vigente. | Registrar el cierre de la asignación 97 con motivo y auditoría; habilitar la operación de desvinculación. |
| **OJG526** (equipo 7) | El chofer sí fue desvinculado de OJG526 el 07/10/2026 (asignación 4 cerrada). Pero sigue vigente en NEB021 (asignación 97 abierta), y el mensaje de las 08:00 es de **NEB021**, no de OJG526. | Registrar también el cierre de la asignación 97 si el chofer ya no debe operar NEB021; auditar el cambio. |

En ambos escenarios, el envío no provino de una asignación histórica ni de una cola persistente: provino de la asignación **vigente** 97. La diferencia es si el cliente considera que esa vigencia sigue siendo válida o no; el sistema, hoy, no tiene forma de saberlo ni de registrar el cambio sin reemplazo.

---

## 5. Auditoría del código

### 5.1 Circuito completo del recordatorio semanal

```
POST /mantenimiento/internal/cron/notifications/dispatch   (cada 5 min, X-Cron-Token)
  -> App\Controllers\NotificationCron::dispatchWithToken()
     -> service('notificationCycle')->execute()
        -> App\Application\Notifications\RunNotificationCycle::execute()
           -> etapa notificaciones:ciclo:despacho
              -> App\Application\Notifications\RunNotificationDispatch::dispatchWhatsApp()
                 -> scheduleWeeklyReadingReminders()      // genera y encola
                 -> due($limit)                           // selecciona y revalida
                 -> gateway->sendText()                   // envía
                 -> accepted() / failed()                 // traza
```

Archivos y líneas clave:

| Responsabilidad | Archivo:línea |
|---|---|
| Cron HTTP y token | `app/Controllers/NotificationCron.php:15-18, 86-130, 176-184` |
| Orquestación del ciclo | `app/Application/Notifications/RunNotificationCycle.php:69-130` |
| Generación + despacho WhatsApp | `app/Application/Notifications/RunNotificationDispatch.php:237-274` |
| Consulta de elegibilidad (generación) | `CodeIgniterWhatsAppNotificationDeliveryQueue.php:707-731` |
| Inserción idempotente de la entrega | ídem `:819-868` |
| Aviso interno a responsables | ídem `:946-1023` |
| Revalidación antes del despacho | ídem `:920-933` y `:1157-1166` |
| Solo se revalida el tipo semanal | ídem `:1146-1149` |
| Mensaje | `app/Application/Notifications/ReadingReminderMessageBuilder.php` |
| Acuse de lectura | `app/Infrastructure/Notifications/CodeIgniterReadingReceiptNotifier.php:49-166, 199-212` |

### 5.2 Criterios de elegibilidad: generación vs. despacho

| Criterio | Generación (`scheduleWeeklyReadingReminders`) | Despacho (`isCurrentDriverAssignment`) |
|---|---|---|
| Empresa/equipo/empleado | sí | sí |
| Rol `CHOFER` | sí | sí |
| `fecha_hasta IS NULL` | sí | sí |
| Empleado `activo = 1` | **sí** | **no** |
| Empleado `deleted_at IS NULL` | **sí** | **no** |
| Equipo `estado = 'ACTIVO'` | **sí** | **no** |
| Equipo `deleted_at IS NULL` | **sí** | **no** |
| Tipo `controla_km = 1` | **sí** | **no** |
| Empresa activa / no borrada | **sí** | **no** |
| WhatsApp de empresa habilitado | **sí** | **no** |
| `fecha_desde <= hoy` | no | no |
| `fecha_hasta >= hoy` (vigencia real) | no (solo NULL) | no (solo NULL) |

**Hallazgo:** el despacho valida menos que la generación. Si el empleado se desactiva o el equipo pasa a BAJA **sin cerrar la fila de asignación**, un mensaje ya encolado sigue siendo elegible.

### 5.3 ¿Se revalida antes del envío efectivo?

- **Recordatorio semanal:** sí, en `due()`, inmediatamente antes de armar el lote que `RunNotificationDispatch` envía. Si no pasa, se marca `OMITIDA` con el motivo `“Chofer ya no asignado al equipo al momento del despacho.”` (`:1163`). Es auditable en `notificacion_whatsapp_entregas.ultimo_error`.
- **Reclamo manual** (`equipo.reclamo_manual_lectura`): se resuelve y se envía en la misma ejecución (`ClaimReadingReminder.php:149-304`); no hay ventana de cola.
- **Acuse de lectura** (`equipo.lectura_registrada`): resuelve el chofer vigente al generar (`emp.activo = 1` incluido), pero **no se revalida al despachar**; `due()` lo deja pasar sin control (`:1146-1149`).
- **Resumen de vencimientos** (`equipo.resumen_vencimientos`) y **preventivos** (`preventivo.proximo/vencido`): se encolan y **no se revalidan** en el despacho.

**Ventana residual:** la revalidación ocurre al armar el lote (`due()`), no inmediatamente antes de cada `sendText()`. Entre la selección y el envío hay hasta `whatsappBatchLimit × whatsappSendIntervalMs` (por defecto 5 × 2 s = 10 s) y lo que demore el ciclo. Una desvinculación en esa ventana no se detecta. No explica el incidente de las 08:00 (la fila seguía abierta), pero es una brecha a cerrar con la revalidación just-in-time propuesta en 8.6.

### 5.4 Defectos confirmados por lectura de código

| ID | Defecto | Ubicación | Impacto |
|---|---|---|---|
| D1 | La guarda de despacho no comprueba empleado activo/borrado, equipo activo/borrado, tipo, empresa ni WhatsApp. | `CodeIgniterWhatsAppNotificationDeliveryQueue.php:920-933` | Un mensaje encolado puede salir si la asignación quedó abierta por una baja no transaccional o un cambio de estado. |
| D2 | Solo se revalida `equipo.recordatorio_lectura_semanal`. | ídem `:1146-1149` | Vencimientos, preventivos y acuses no tienen revalidación de destinatario. |
| D3 | `terminate()` hace dos escrituras sin transacción: guarda el empleado y después cierra la asignación. | `app/Application/Employees/EmployeeService.php:110-124` | Si falla la segunda, queda empleado inactivo con asignación abierta; con D1, los recordatorios pendientes siguen saliendo. |
| D4 | “Vigente” se aproxima con `fecha_hasta IS NULL`. | ídem `:920-933`, `:720` | Una asignación con `fecha_desde` futura ya recibe recordatorios; una cerrada con `fecha_hasta` futura se considera no vigente de inmediato. |
| D5 | No hay auditoría de cambios de asignación. | `auditoria` (sin filas de asignaciones) | No se puede reconstruir quién desvinculó, cuándo ni por qué. |
| D6 | No existe operación de desvinculación sin reemplazo. | `EquipmentManagement.php:152-182`; `EquipmentDetailPage.vue:155-173` | El administrador no puede expresar la intención; el síntoma se repite. |
| D7 | Las unidades en taller / fuera de servicio no participan del criterio. | `CodeIgniterWhatsAppNotificationDeliveryQueue.php:723` | Se envían recordatorios a unidades con OT abierta; `equipo_fuera_servicio` nunca se usa. |
| D8 | Intervalos de asignación inválidos heredados de la importación (`fecha_hasta < fecha_desde`). | 4 filas en producción | No causan envíos hoy (se tratan como cerradas), pero ensucian la historia y cualquier cálculo de vigencia. |

### 5.5 Circuitos revisados y cambios posteriores a #471

| Circuito | Implementación | Revalidación al enviar | Observación |
|---|---|---|---|
| Recordatorio semanal (inicial / miércoles / viernes) | `scheduleWeeklyReadingReminders` | Sí (`isCurrentDriverAssignment`) | Es el que originó el mensaje. |
| Reclamo manual de lectura | `ClaimReadingReminder` | No aplica: resuelve y envía en la misma ejecución | Exige equipo activo, chofer activo y lectura atrasada. |
| Reintentos de WhatsApp | `failed()` / `due()` | Solo el tipo semanal | Los reintentos de otros tipos no se revalidan. |
| Cola persistente | `notificacion_whatsapp_entregas` | Parcial | Ver D1/D2. |
| Procesos programados | `NotificationCron` → `RunNotificationCycle` cada 5 min | — | Sin CLI en Ferozo; cron HTTP con token. |
| Desvinculación de choferes | `assignDriver` / `terminate` | — | No existe desvinculación sin reemplazo (D6). |
| Cambio de estado operativo de unidades | `equipos.estado` (ACTIVO/BAJA) + OT | — | No hay estado de taller; la bandera de OT no se usa (D7). |
| Acuse de lectura | `CodeIgniterReadingReceiptNotifier` | No | Resuelve el chofer vigente al generar. |
| Vencimientos y preventivos al chofer | `scheduleDriverForEvent` / `schedulePreventiveDriverEvent` | No | Sin revalidación en el despacho. |

Cambios revisados (todos posteriores o relacionados con #471):

| Commit | Fecha | Qué hizo | ¿Afecta la elegibilidad? |
|---|---|---|---|
| `719d68e2` (#449) | 2026-10-07 | Agregó `isCurrentDriverAssignment` y la revalidación en `due()`. | Sí, es la guarda vigente. |
| `c0d69f68` (#471) | 2026-10-08 | Unificó el generador de mensajes. | No. |
| `691ee688` (#457) | 2026-10-08 | Excluyó avisos semanales ya regularizados del resumen diario. | No. |
| `74fadec9` (#474) | 2026-10-09 | Regularizó alertas obsoletas de celulares de choferes. | No. |
| `4c4355e7` | 2026-11-05 | Agregó el acuse de lectura por WhatsApp. | No al semanal; introduce el tipo sin revalidación (D2). |
| `05d6c63e` | 2026-10-23 | Informe de equipos sin lectura para gerencia. | No (va a gerencia, no al chofer). |

---

## 6. Auditoría global de producción

### 6.1 Universo y regla de clasificación

Se reconstruyeron los intervalos `[fecha_desde, fecha_hasta]` por `(empresa, equipo, empleado)` para todos los equipos que alguna vez recibieron recordatorios de km, y se clasificó cada entrega contra el intervalo vigente **al momento del envío** (`enviada_en`, o `created_at` si no se envió). Una entrega “fuera de intervalo” es la única forma de demostrar un envío a un chofer no asignado en ese momento.

### 6.2 Resultado

| Consulta | Resultado |
|---|---|
| Envíos de recordatorio/reclamo dentro de intervalo (desde 01/09/2026) | 302 (279 semanales aceptados + 2 semanales pendientes de confirmación + 21 reclamos) |
| **Envíos efectivos fuera de todo intervalo** | **4** (todos 22/09/2026, ver 6.3) |
| Envíos efectivos fuera de intervalo desde 01/10/2026 | **0** |
| Pendientes/REINTENTO de km a choferes sin asignación vigente | **0** |
| Pendientes a empleados inactivos | **0** |
| Recordatorios a equipos no ACTIVO o borrados | **0** |
| Asignaciones abiertas con empleado inactivo | **0** |
| Asignaciones abiertas con equipo no activo | **0** |
| Equipos con más de una asignación abierta | **0** |
| Empleados con más de una asignación abierta | **0** |
| Asignaciones con `fecha_hasta < fecha_desde` | **4** (ids 88, 19, 37, 91) |
| Recordatorios de km enviados con una OT abierta | **41**, sobre **6 equipos** y **8 choferes** |
| Recordatorios con `equipo_fuera_servicio = 1` | **0** (la bandera nunca se usó) |

### 6.3 Los 4 envíos fuera de intervalo

Todos son del **22/09/2026**, a los empleados 19 (NEVEZ LEONARDO) y 37 (FRITZEN JOSE), con asignaciones cuyo `fecha_hasta` (2026-01-10) es **anterior** a `fecha_desde` (2026-09-10): datos inválidos de la importación inicial. Al momento del envío el sistema los tenía activos; la baja con fecha retroactiva al 10/01/2026 se registró recién el 01/10/2026 (`empleados.updated_at`). Son anteriores a los hotfixes #365 (cola duplicada) y #449 (revalidación). Se documentan como **regresión histórica cerrada**, no como fuga vigente.

### 6.4 Casos que parecían fuga y no lo son

| Caso | Por qué no es fuga |
|---|---|
| Empleado 95 (MIGUEL SEVERO), equipo 16, recordatorios del 16 al 25/11 | Estaba asignado desde el 11/11 y activo. Se dio de baja el 25/11 17:10 con fecha efectiva 20/12. Los envíos fueron anteriores a la baja. |
| Empleado 25 (MATLUK MARCELO), equipo 31, envíos del 16 y 23/11 | Tenía una segunda asignación abierta al equipo 31 desde el 11/11 (id 119). El `fecha_hasta = 26/10` que aparecía era de la asignación anterior (id 25). |
| Empleado 25, acuses de lectura del 05 y 09/11 por equipo 16 | Estaba asignado al equipo 16 del 26/10 al 11/11. |
| Avisos de vencimiento del 18 y 20/09 al “Chofer Piloto WhatsApp” | Datos de prueba de la empresa demo 7, previos al circuito actual. |

### 6.5 Aislamiento multitenant

- El caso está íntegramente en empresa 4 (TSA). La empresa 7 (demo) solo aparece en datos de piloto.
- Todas las consultas filtraron por `empresa_id`; no se detectaron cruces de empresa en entregas, asignaciones ni equipos.
- El teléfono del chofer y los destinatarios coinciden con la empresa de la entrega.

---

## 7. Comportamiento esperado vs. actual

La regla pedida es: *un recordatorio al chofer de una unidad solo debe enviarse si existe una asignación válida y vigente al momento del envío; si el chofer se desvincula entre la generación y el envío, el mensaje debe quedar cancelado con motivo auditable.*

| Requisito | Estado actual | Evidencia |
|---|---|---|
| Asignación válida y vigente al enviar | **Parcial**: se exige fila abierta, no vigencia real ni empleado/equipo activos | D1, D4 |
| Cancelación si cambia el chofer entre generación y envío | **Cumplido** para el recordatorio semanal | `due()` 1157-1166; motivo en `ultimo_error` |
| Motivo auditable | **Parcial**: texto libre en `ultimo_error`; sin código de motivo ni auditoría estructurada | D5 |
| No afectar otras notificaciones legítimas | **Cumplido**: la revalidación solo toca el tipo semanal | `:1146-1149` |
| Unidades en taller/fuera de servicio | **No implementado**: el sistema no tiene estado de taller y la bandera de OT no se usa | D7 |

**Sobre “unidades en taller”:** no se modificó ni se debe modificar esta regla sin autorización. Hoy el sistema no tiene un estado de taller a nivel equipo; la única señal posible es una OT abierta con `equipo_fuera_servicio = 1`, una bandera que nunca se usó en producción y que el circuito de recordatorios no consulta. Cualquier exclusión automática es una decisión de negocio pendiente.

---

## 8. Propuesta de corrección (no implementada)

> Nada de esto se aplicó. Requiere autorización explícita. El orden propuesto es por riesgo, no por tamaño.

### P0 — Cerrar la brecha que originó el incidente

1. **Agregar la operación “Desvincular chofer” (sin reemplazo).**
   - **Dominio:** `EmployeeEquipmentAssignment::close(DateTimeImmutable $endsAt, string $reason)` con invariantes: no cerrar dos veces; `endsAt >= startsAt`; motivo obligatorio; no aceptar fechas futuras salvo decisión expresa.
   - **Aplicación:** caso de uso `UnassignDriverFromEquipment` (empresa, equipo, fecha, motivo, actor). Transacción única: cierra la asignación vigente + escribe auditoría. Idempotente. Falla si no hay asignación vigente.
   - **Puerto:** `EmployeeAssignmentRepository::closeCurrentForEquipment(int $companyId, int $equipmentId, DateTimeImmutable $endsAt, string $reason, int $actorUserId)` y un puerto de auditoría.
   - **Infraestructura:** implementación con `FOR UPDATE` + transacción; fila en `auditoria` con `accion = 'asignacion.cerrada'`, `entidad = 'employee_equipment_assignments'`, `valores_anteriores`/`valores_nuevos` y `motivo`.
   - **Presentación:** `POST /mantenimiento/equipos/{id}/chofer/desvincular` con `empleados.editar` + CSRF; botón “Desvincular chofer” en la ficha, con fecha y motivo obligatorios; confirmación; estado “Sin chofer asignado” visible.
2. **Cerrar la asignación 97 de producción** (chofer 4 / NEB021) **solo con autorización explícita** y motivo registrado (`“Unidad siniestrada / en taller”`). No hacerlo como parte del deploy ni por script automático; es un dato funcional del cliente.
3. **Auditar la desvinculación** desde el primer día: quién, cuándo, equipo, chofer, motivo y fecha efectiva. Sin auditoría, el próximo incidente vuelve a ser indeterminable.

### P0 — Endurecer la revalidación de envío

4. **Un solo predicado de elegibilidad**, compartido por generación y despacho, que compruebe:
   - asignación `rol = CHOFER`, `fecha_desde <= hoy`, `fecha_hasta IS NULL OR fecha_hasta >= hoy`;
   - empleado `activo = 1`, `deleted_at IS NULL`;
   - equipo `estado = 'ACTIVO'`, `deleted_at IS NULL`;
   - tipo `controla_km = 1`;
   - empresa `estado = 1`, `deleted_at IS NULL`, WhatsApp habilitado.
5. **Aplicarlo a todos los tipos que se envían a un chofer**, no solo al recordatorio semanal: `equipo.resumen_vencimientos`, `preventivo.proximo`, `preventivo.vencido` y `equipo.lectura_registrada`.
6. **Revalidar justo antes de `sendText()`**, no solo al armar el lote: releer la entrega y el estado del chofer/equipo inmediatamente antes de llamar al gateway, para cerrar la ventana entre `due()` y el envío (que puede incluir `usleep` por lote).
7. **Cancelación con motivo estructurado:** además del texto en `ultimo_error`, guardar un código (`CHOFER_NO_VIGENTE`, `EQUIPO_FUERA_SERVICIO`, `EMPRESA_SIN_WHATSAPP`, `TIPO_SIN_KM`) y una fila de auditoría. Contabilizar las cancelaciones en el resumen del ciclo.

### P1 — Consistencia de la baja

8. **Hacer transaccional `EmployeeService::terminate()`**: empleado + cierre de **todas** las asignaciones abiertas + auditoría en una sola transacción, con rollback completo.
9. **Cerrar todas las asignaciones abiertas del empleado**, no solo la primera (`closeCurrentForEmployee` hoy actualiza todas las que tengan `fecha_hasta IS NULL`, pero sin transacción ni auditoría; verificar el contrato).

### P1 — Regla de negocio pendiente (requiere decisión)

10. **Definir si una unidad en taller / fuera de servicio debe excluirse.** Opciones:
    - usar `ordenes_trabajo.equipo_fuera_servicio = 1` con OT abierta como exclusión; o
    - agregar un estado operativo explícito al equipo (`EN_TALLER`, `FUERA_SERVICIO`).
    No implementar hasta que el cliente confirme la regla, porque cambia el universo de recordatorios y puede ocultar deuda de kilómetros legítima.

### P2 — Calidad de datos

11. **Revisar los 4 intervalos inválidos** (`fecha_hasta < fecha_desde`, ids 88, 19, 37, 91) y las bajas con fecha futura (empleado 95). No corregir automáticamente: son datos históricos y cualquier normalización debe quedar auditada.
12. **Documentar el contrato** en `docs/CONTRATOS_ENTRE_CONTEXTOS.md` y la regla de elegibilidad en `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md`.

---

## 9. Tests de regresión necesarios

### Dominio (sin DB)
- `EmployeeEquipmentAssignment::close()`: cierra con motivo y fecha; rechaza doble cierre; rechaza `endsAt < startsAt`; rechaza motivo vacío; no muta `fecha_desde`.
- Una asignación con `fecha_desde` futura **no** es elegible hoy.
- Una asignación con `fecha_hasta` futura **sí** es elegible hasta esa fecha (corrige D4).

### Aplicación (puertos falsos)
- `UnassignDriverFromEquipment` cierra exactamente la fila vigente del equipo, escribe auditoría con antes/después/motivo y es idempotente.
- Si no hay asignación vigente, falla con un error de dominio claro.
- `terminate()` cierra **todas** las asignaciones abiertas y hace rollback completo si falla el cierre (test de transacción).

### Infraestructura / contrato de cola
- `due()` omite y marca `OMITIDA` una entrega semanal `PENDIENTE` cuando:
  - la asignación se cerró después de generar;
  - el empleado pasó a `activo = 0`;
  - el empleado tiene `deleted_at`;
  - el equipo pasó a `BAJA` o `deleted_at`;
  - el tipo dejó de controlar km;
  - la empresa quedó inactiva o sin WhatsApp.
- El motivo queda en `ultimo_error` y en la auditoría (código + texto).
- No se tocan entregas de otra empresa, otro equipo ni otro tipo.
- Un `PENDIENTE_CONFIRMACION`/`REINTENTO` también se revalida antes del reenvío.
- Carrera: generar a las T0 con asignación abierta, cerrar a T1, despachar a T2 → no se envía.

### HTTP / feature
- `POST /equipos/{id}/chofer/desvincular` exige CSRF y `empleados.editar`; rechaza equipo de otra empresa; rechaza fecha futura; registra auditoría; redirige con éxito.
- La ficha muestra “Sin chofer asignado” y el historial con fecha y motivo.
- La ruta nueva aparece en `tests/feature/RouteResolutionTest.php` y en el catálogo de módulos.

### Regresión histórica
- Los 4 envíos del 22/09/2026 quedan cubiertos por un test que documente que un intervalo inválido no habilita envíos.
- Test de que un equipo con OT abierta **no** se excluye por defecto (hasta que se autorice la regla contraria).

---

## 10. Plan de despliegue quirúrgico (para cuando se autorice)

1. **Rama y alcance:** `fix/recordatorios-chofer-desvinculado`. Un PR pequeño por bloque: (a) desvinculación + auditoría; (b) revalidación unificada; (c) transacción de baja. No mezclar con refactors.
2. **Migraciones:** solo si se agrega `motivo_cierre` a asignaciones o un estado operativo. Migración nueva, `down()` seguro, sin editar migraciones ya aplicadas.
3. **Staging obligatorio:** `fasa_189`, stack `mantenimiento-staging` en Coolify, con volumen preservado. Validar allí el flujo completo: asignar → desvincular → verificar que la cola omite el pendiente con motivo → reasignar → verificar que el nuevo chofer recibe.
4. **Orden de subida a Ferozo (layout plano):** primero dominio/aplicación (clases nuevas), después repositorios, después controladores/vistas/rutas, al final la cola. Nunca subir un consumidor antes que la clase que referencia.
5. **Antes del deploy:** backup de base y de `app/`, snapshot de `notificacion_whatsapp_entregas` pendientes y de las asignaciones abiertas; confirmar que no hay un ciclo de notificaciones corriendo.
6. **Después del deploy:** smoke de login, ficha de equipo, alta/baja de asignación en staging, y verificación de que el cron sigue cerrando ciclos (`automatizacion_etapas`). En producción, una consulta de solo lectura para confirmar que no quedaron pendientes hacia choferes no vigentes.
7. **Remediación de datos separada:** cerrar la asignación 97 solo con autorización y motivo; no incluirla en el deploy. Revisar los 4 intervalos inválidos con el cliente.
8. **Rollback:** restaurar los archivos previos desde el backup; las migraciones nuevas son aditivas y nullable, por lo que no requieren reversión inmediata. Si se revierte la cola, las entregas siguen en cola y no se pierden.
9. **No tocar:** otras notificaciones legítimas, cron, configuración de WhatsApp, tokens públicos ni la asignación 97 sin autorización.

---

## 11. Artefactos de la auditoría

| Artefacto | Ruta | Contenido |
|---|---|---|
| Informe principal | `tmp/auditoria-recordatorios-desvinculados-2026-11-27.json` | Contexto, chofer, asignaciones, entregas, etapas, auditoría global |
| Intervalos y clasificación | `tmp/auditoria-recordatorios-desvinculados-paso2-2026-11-27.json` | 41 asignaciones, 4 envíos fuera de intervalo, órdenes abiertas |
| Unidades fuera de servicio | `tmp/auditoria-recordatorios-desvinculados-paso3-2026-11-27.json` | OT abiertas, bandera `equipo_fuera_servicio`, configuración de canales |
| Auditoría de cambios | `tmp/auditoria-recordatorios-desvinculados-paso4-2026-11-27.json` | Tabla `auditoria`, importación, historial de casos |
| Órdenes y siniestro | `tmp/auditoria-recordatorios-desvinculados-paso5-2026-11-27.json` | OTs históricas de 7/43, observaciones, tablas de siniestro |
| Drift de código | `tmp/auditoria-recordatorios-desvinculados-drift.json` | Hashes de producción vs. local |
| Scripts reproducibles | `scripts/auditoria-recordatorios-desvinculados*.php`, `scripts/run-auditoria-recordatorios-desvinculados*.py` | Plantillas y runners de solo lectura |

`tmp/` está ignorado por Git porque contiene datos de producción.

---

## 12. Decisión requerida

Para avanzar sin repetir el incidente, se necesita autorización sobre tres puntos:

1. **Implementar “Desvincular chofer”** (con motivo y auditoría) y **cerrar la asignación 97** de NEB021 con el motivo que indique el cliente.
2. **Endurecer la revalidación de envío** para todos los tipos al chofer, incluyendo empleado/equipo activos y vigencia real de fechas.
3. **Definir la regla de unidades en taller / fuera de servicio**: si se excluyen, con qué fuente (`OT.equipo_fuera_servicio` o un estado operativo nuevo).

Hasta entonces, el sistema seguirá considerando a WALTER BROEMSER chofer vigente de NEB021. Si nadie corrige la asignación 97 antes del lunes 30/11/2026, el recordatorio inicial de la semana W49 volverá a generarse y enviarse.
