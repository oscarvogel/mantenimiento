# #594 — Elegibilidad única de destinatarios WhatsApp (chofer)

**Fecha:** 27/11/2026
**Rama:** `feat/594-elegibilidad-whatsapp`
**Issue:** #594 — P1: Centralización y endurecimiento de la elegibilidad de destinatarios de WhatsApp
**Base:** merge del PR #597 (`07af593e`)

---

## 1. Problema

La auditoría del 27/11/2026 (`docs/AUDITORIA_RECORDATORIOS_CHOFERES_DESVINCULADOS_2026-11-27.md`,
defectos D1, D2 y D4) encontró que la generación y el despacho usaban criterios distintos:

- la generación exigía empleado y equipo activos, tipo que controla km y empresa con WhatsApp;
- la guarda de despacho (`isCurrentDriverAssignment`) solo verificaba una fila abierta;
- solo se revalidaba el recordatorio semanal;
- la revalidación corría al armar el lote (`due()`), no inmediatamente antes de `sendText()`;
- “vigente” se aproximaba con `fecha_hasta IS NULL`.

## 2. Solución

### 2.1 Predicado único (dominio puro)

| Archivo | Rol |
|---|---|
| `app/Domain/Notifications/DriverNotificationTypes.php` | Lista única de tipos dirigidos al chofer. |
| `app/Domain/Notifications/DriverAssignmentFacts.php` | Hechos mínimos de la asignación. |
| `app/Domain/Notifications/DriverNotificationEligibility.php` | Resultado elegible/bloqueado con código y texto. |
| `app/Domain/Notifications/DriverNotificationEligibilityPolicy.php` | Decide el primer criterio incumplido. |

Criterios, en orden: asignación `CHOFER` y `fecha_desde <= hoy`; `fecha_hasta IS NULL OR >= hoy`;
empleado no borrado y activo; equipo no borrado y `ACTIVO`; tipo `controla_km = 1`; empresa no
borrada, activa y con WhatsApp habilitado.

Una asignación que termina **hoy** sigue vigente hoy. Una que empieza mañana no lo está.

### 2.2 Puerto y adaptador

- `app/Application/Notifications/Port/DriverNotificationEligibilityReader.php`
  - `evaluate(empresa, equipo, empleado, hoy)`
  - `findCurrentDriver(empresa, equipo, hoy)`
- `app/Infrastructure/Notifications/CodeIgniterDriverNotificationEligibilityReader.php` carga los
  hechos con una consulta y delega en la política. La consulta **no** filtra por estado: solo elige
  la fila que corresponde evaluar.

### 2.3 Generación

Usan el predicado único:

- recordatorio semanal (`scheduleWeeklyReadingReminders`) — la consulta carga candidatos y el bucle
  evalúa antes de marcar la unidad como atendida;
- resumen de vencimientos (`scheduleDriverForEvent`) — el chofer y las unidades del resumen salen de
  `findCurrentDriver`/`evaluate`;
- preventivos (`schedulePreventiveDriverEvent`) — `findCurrentDriver`;
- acuse de lectura (`CodeIgniterReadingReceiptNotifier`) — `findCurrentDriver`;
- reclamo manual (`ClaimReadingReminder`) — `findCurrentDriver` en lugar de su SQL propio.

Se eliminó `ACTIVE_DRIVER_SQL` de los dos adaptadores que lo duplicaban.

### 2.4 Despacho

`due()` aplica el predicado a **todos** los tipos de `DriverNotificationTypes` antes de armar el
lote. `RunNotificationDispatch` vuelve a revalidar con `revalidateBeforeSend()` inmediatamente antes
de `sendText()`, incluso en reintentos.

Si la entrega ya no es elegible:

- `estado = 'OMITIDA'`;
- `motivo_cancelacion = <CÓDIGO>`;
- `ultimo_error = "<CÓDIGO>: <texto>"`;
- `proximo_intento = NULL`;
- fila en `auditoria` con `accion = notificacion.whatsapp_cancelada`;
- contadores `whatsapp_cancelled` y `whatsapp_cancelled_<motivo>` en el resumen del ciclo.

### 2.5 Códigos de cancelación

`CHOFER_SIN_ASIGNACION`, `ASIGNACION_NO_INICIADA`, `ASIGNACION_CERRADA`, `EMPLEADO_INACTIVO`,
`EMPLEADO_ELIMINADO`, `EQUIPO_INACTIVO`, `EQUIPO_ELIMINADO`, `TIPO_SIN_KILOMETRAJE`,
`EMPRESA_INACTIVA`, `EMPRESA_ELIMINADA`, `WHATSAPP_EMPRESA_DESHABILITADO`.

## 3. Migración

`2026-11-27-120000_AddCancellationReasonToWhatsAppDeliveries`

- agrega `notificacion_whatsapp_entregas.motivo_cancelacion VARCHAR(40) NULL`;
- es aditiva, nullable e idempotente;
- `down()` elimina la columna.

`cancelled()` tolera que el archivo llegue antes que la migración: en ese caso guarda el código en
`ultimo_error` y no rompe el despacho. Aun así, el orden recomendado es **migración primero**.

## 4. Pruebas

| Suite | Resultado |
|---|---|
| `DriverNotificationEligibilityPolicyTest` | 16 tests: cada criterio, bordes de fecha y orden de precedencia. |
| `DriverNotificationEligibilityReaderTest` | 19 tests: cada criterio contra SQLite, aislamiento por empresa y elección del chofer vigente. |
| `WhatsAppDeliveryEligibilityTest` | 17 tests: `due()` cancela con código, auditoría y contadores; no toca otras unidades ni tipos; revalidación previa al envío; idempotencia. |
| `NotificationDispatchEligibilityTest` | 3 tests: no se envía una entrega bloqueada, se envía la elegible y se suman los contadores. |
| `AddCancellationReasonToWhatsAppDeliveriesMigrationTest` | 2 tests: `up()`/`down()` idempotentes. |
| Suite completa | **2542 tests, 9601 assertions, 52 skipped**; único error local de Windows `symlink(): Permission denied` en `ArchivoRespaldoBucketsTest` (ajeno a este cambio). |

Contratos actualizados: `WhatsAppWeeklyReadingReminderContractTest` y
`ReadingControlReadOnlyContractTest`.

## 5. Despliegue

1. Aplicar la migración.
2. Subir dominio y aplicación (clases nuevas y casos de uso).
3. Subir adaptadores (lector, cola, acuse, reclamo) y el wiring de `Services`.
4. Verificar el ciclo del cron y el resumen de la etapa `notificaciones:ciclo:despacho`.

Rollback: los archivos previos desde backup. La columna es nullable y aditiva; no es necesario
revertirla para volver al código anterior.

## 6. Fuera de alcance

- #595 (unidades en taller / fuera de servicio): sigue pendiente de decisión del cliente.
- Corrección de datos históricos (intervalos inválidos, bajas con fecha futura).
- La operación de desvinculación ya entregada en #593.

## 7. Validación en staging — `fasa_189` (27/11/2026)

- **Host verificado:** `fasa_189` (`192.168.0.189`). **No se modificó** el contenedor
  `mantenimiento-staging` (`bdqictyu4q5xkfuh6aoh7sr8-20261127T112812`) ni su base
  `mantenimiento_staging`.
- **Base clonada:** `mantenimiento_594_verify` en el MariaDB 11.8 de staging (134 tablas, 329,8 MB),
  creada y eliminada para esta validación.
- **Contenedor temporal:** `mant-594-validation` desde la imagen
  `bdqictyu4q5xkfuh6aoh7sr8:fe3c5532745530b9a382eb18ea3c2ecf7d3c958b`, con el código de `main`
  (`70d9eafc779048f95d6bed5c208da8f8e05bb0ac`) montado en `app/`, red `coolify`, sin cron, sin SMTP
  y sin envíos reales.
- **Migración:** aplicada con `php spark migrate` (batch 69). Columna
  `motivo_cancelacion varchar(40) NULL`. `up()`/`down()` idempotentes verificados sobre MariaDB,
  incluida la reaplicación.
- **21 comprobaciones PASS** sobre la app real y MariaDB real:
  - `findCurrentDriver` y `evaluate` con asignación vigente;
  - `due()` incluye la entrega elegible;
  - asignación cerrada → `OMITIDA` + `ASIGNACION_CERRADA` + texto + auditoría;
  - asignación que termina hoy → sigue elegible;
  - empleado inactivo → `EMPLEADO_INACTIVO`;
  - tipo sin kilometraje → `TIPO_SIN_KILOMETRAJE`;
  - empresa sin WhatsApp → `WHATSAPP_EMPRESA_DESHABILITADO`;
  - `revalidateBeforeSend` bloquea después de `due()`;
  - un tipo que no va al chofer (`informe.gerencial.diario`) no se filtra;
  - contadores por motivo y total.
- **Staging intacto:** contenedor `healthy`; `mantenimiento_staging` sin la columna nueva;
  contenedor temporal, base clonada y archivos temporales eliminados y verificados.
- **Producción:** no se tocó.

## 8. Despliegue a producción — Ferozo (27/11/2026)

**Orden aplicado:**

1. Backup completo de `/app` en `dist/backup-pre-70d9eafc/app` (1374 archivos, 175 directorios,
   5.221.500 bytes).
2. Baseline verificado contra `07af593e`: 8/9 archivos idénticos; `Services.php` solo difería por
   CRLF/LF (contenido normalizado idéntico).
3. Subida del archivo de migración y de `migrate.php`; migración aplicada por HTTP con token.
   - `2026-11-27-120000` aplicada, **batch 60**.
   - Columna `motivo_cancelacion varchar(40) NULL` confirmada.
   - `migrate.php` eliminado y verificado 404.
4. Subida de los 15 archivos restantes: **15/15 hashes SHA-256 OK** (más el de la migración).
5. Smoke: `/` 302, `/login` 200, manifest 200, `.env`/`app` 403, `migrate.php` 404, ficha canónica 302.

**Incidente y corrección:**

- El ciclo de las **16:50** falló en `notificaciones:ciclo:recoleccion` con
  `Error: Call to a member function diff() on string`: en `scheduleDriverForEvent` la fecha pasó a
  string para el predicado único y quedó un `$today->diff($expires)`.
- Corrección de una línea (`$todayDate->diff($expires)`) subida de inmediato (hash verificado).
- **PR #601** (`817a508b996ce43e14a01b9a7ca0583d1ec3b03f`) con la corrección y una prueba funcional
  nueva (`WhatsAppExpirationDigestScheduleTest`) que cubre el resumen para chofer elegible y no
  elegible.
- Ciclos posteriores: **16:55, 17:00 y 17:05 completos**, incluida `recoleccion` (65 eventos) y
  `despacho` con el nuevo contador `whatsapp_cancelled`.
- Única etapa fallida desde las 16:45: la `recoleccion` de las 16:50.

**Estado final:**

- 0 entregas `PENDIENTE`/`REINTENTO` con destinatario inelegible.
- 0 cancelaciones nuevas (no había pendientes inelegibles al momento del deploy).
- Cola: 2 recordatorios semanales en `PENDIENTE_CONFIRMACION` (ya enviados), sin cambios.
- `migrate.php` ausente; `writable/` y el resto del sitio sin cambios.
- Backup de rollback disponible en `dist/backup-pre-70d9eafc/app`.
