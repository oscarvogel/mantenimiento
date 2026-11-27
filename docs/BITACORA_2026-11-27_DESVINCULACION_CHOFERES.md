# Bitácora 2026-11-27 — Desvinculación de choferes sin reemplazo (#593)

## Alcance

Corrección del P0 de la auditoría `docs/AUDITORIA_RECORDATORIOS_CHOFERES_DESVINCULADOS_2026-11-27.md`: permitir dejar una unidad sin chofer sin dar de baja al empleado, cerrar la asignación de forma transaccional, auditar el cambio y cancelar las entregas de WhatsApp pendientes de esa asignación.

No se ejecutó ninguna corrección sobre la asignación real 97 (TSA / NEB021 / empleado 4). El caso se regulariza por la pantalla, con confirmación del administrador, una vez desplegada la funcionalidad.

## Decisión de diseño

- **No se inventó un “estado sin chofer” en `equipos`.** La unidad sigue `ACTIVO`; lo que cambia es la asignación vigente.
- **El cierre no exige reemplazo.** La asignación se cierra con `fecha_hasta` y un motivo obligatorio. El empleado permanece activo y puede reasignarse.
- **El motivo y las observaciones de cierre tienen columnas propias** (`motivo_cierre`, `observaciones_cierre`) para no pisar `observaciones`, que sigue siendo la nota original del alta.
- **La fecha efectiva no puede ser futura.** Se evita repetir el defecto de una `fecha_hasta` futura que la guarda actual interpretaría como “no vigente” antes de tiempo. Una desvinculación programada queda como mejora separada.
- **La transacción es del caso de uso.** Cierre, cancelación de entregas y auditoría se confirman o revierten juntos. Si la auditoría falla, no queda nada a medias.
- **La cancelación solo toca entregas no enviadas.** `PENDIENTE` y `REINTENTO` pasan a `OMITIDA` con el motivo en `ultimo_error`. `ACEPTADA` y `PENDIENTE_CONFIRMACION` ya salieron y no se tocan.
- **Se agregó una relectura antes de `sendText()`.** Una entrega seleccionada por `due()` que fue cancelada por una desvinculación concurrente ya no se envía. Es una garantía de cancelación de este issue; la centralización general de elegibilidad queda para #594.

## Cambios

### Dominio

- `EmployeeEquipmentAssignment::close()` ahora exige motivo (5–255) y acepta observaciones (≤1000). Conserva `observaciones` original y expone `closureReason()` / `closureNotes()`.

### Aplicación

- `UnassignDriver` (#593): valida `empleados.editar`, empresa activa, fecha no futura, motivo y observaciones; ejecuta en una transacción; cierra, cancela y audita; es idempotente por unidad.
- `UnassignDriverResult`, `ClosedDriverAssignment`.
- `EmployeeUnitOfWork`, `PendingDriverNotifications`, `EmployeesClock`.
- `EmployeeService::terminate()` ahora corre dentro de `EmployeeUnitOfWork`: empleado y asignaciones se confirman o revierten juntos. Además propaga el motivo de baja a `motivo_cierre`.

### Infraestructura

- `CodeIgniterEmployeeUnitOfWork`.
- `SystemEmployeesClock`.
- `CodeIgniterPendingDriverNotifications`: cancela entregas del chofer para esa unidad, filtrando empresa/equipo/empleado y tipos de destinatario chofer.
- `CodeIgniterEmployeeAssignmentRepository::closeCurrentForEquipment()`: bloquea equipo y asignación (`FOR UPDATE` en MySQL), actualiza una sola fila y devuelve la fotografía del cierre. Si no hay chofer vigente, devuelve `null` (idempotencia).
- `CodeIgniterEmployeeRepository::findForUpdate()` y las consultas de asignaciones omiten `FOR UPDATE` en SQLite para las pruebas.
- `CodeIgniterWhatsAppNotificationDeliveryQueue::stillPending()` y relectura en `RunNotificationDispatch` antes de enviar.
- Migración `2026-11-27-090000_AddClosureAuditToEmployeeEquipmentAssignments`.

### Presentación

- Ruta `POST /mantenimiento/equipos/{id}/chofer/desvincular` con `permission:empleados.editar` y CSRF global.
- `EquipmentManagement::unassignDriver()`: fecha, motivo, observaciones; mensaje de éxito con cantidad de mensajes cancelados; si ya no hay chofer, informa sin modificar el historial.
- Ficha del equipo: acción “Desvincular chofer” con fecha (máximo hoy), motivo obligatorio, observaciones, confirmación y explicación de que el empleado sigue activo. El historial muestra motivo y observaciones de cierre.
- `assets/dashboard` reconstruido con el bundle nuevo.

## Pruebas

Ejecutadas localmente con PHP 8.2.12 y SQLite 3:

| Prueba | Resultado |
|---|---|
| `EmployeeEquipmentAssignmentTest` | 6 tests, OK |
| `UnassignDriverTest` (aplicación, puertos falsos) | 7 tests, OK |
| `UnassignDriverContractTest` (ruta, controlador, vista, migración) | 4 tests, OK |
| `NotificationDispatchCancellationTest` (cancelación concurrente) | 1 test, OK |
| `UnassignDriverIntegrationTest` (SQLite real) | 5 tests, 61 assertions, OK |
| `EmployeeTerminationAtomicityTest` (rollback real) | 2 tests, OK |
| Notificaciones + reclamo manual (regresión) | 266 tests, 1376 assertions, OK |
| Rutas + controladores + migraciones | 34 tests, 615 assertions, OK |
| Suite PHPUnit completa | 2451 tests, 9245 assertions, 52 skipped, 1 error de entorno |
| Frontend Vitest | 97 archivos, 618 tests, OK |

El único error de la suite completa es `ArchivoRespaldoBucketsTest::testRechazaUnEnlaceSimbolicoQueSaleDeWritable` (`symlink(): Permission denied`), una limitación de Windows que no toca este cambio. En CI Linux no aplica.

La suite completa incluye los tests de integración SQLite, que en CI se saltan porque el workflow no instala `sqlite3`; localmente se ejecutaron con `-d extension=sqlite3`.

## Cobertura de los requisitos del issue

| Requisito | Dónde se prueba |
|---|---|
| Desvinculación sin reemplazo | `UnassignDriverTest`, `UnassignDriverIntegrationTest` |
| Empleado permanece activo | `UnassignDriverIntegrationTest` (assert `activo = 1`) |
| Equipo permanece activo | `UnassignDriverIntegrationTest` (assert `estado = ACTIVO`) |
| No se generan nuevos recordatorios | `schedulerCandidates = 0` y `currentDriverForEquipment = null` |
| Cancelación de pendientes | entregas 100/101 a `OMITIDA`; aceptada y otros destinatarios intactos |
| Reasignación posterior | `testReassignmentAfterUnassignKeepsBothRowsAndNewDriver` |
| Aislamiento entre empresas | `testRejectsEquipmentFromAnotherCompany` |
| Permisos insuficientes | `UnassignDriverTest::testRejectsActorWithoutPermission` |
| Rollback ante error | `testRollbackWhenAuditFails...` y `EmployeeTerminationAtomicityTest` |
| Concurrencia con despacho | `NotificationDispatchCancellationTest` |
| Regresión de semanales y reclamos | suite de Notifications + `ClaimReadingReminderTest` |

## Validación en staging — `fasa_189` (27/11/2026)

- Host verificado: `fasa_189` (192.168.0.189). Se usó un contenedor temporal `mant-593-validation` a partir de la imagen del staging existente (`fe3c5532`), con el código de la rama montado en `app/` y `assets/`, red `coolify`, puerto 18093, volúmenes temporales y la misma MariaDB de staging. **No se modificó el contenedor `mantenimiento-staging` ni el environment `production`.**
- Migración `2026-11-27-090000_AddClosureAuditToEmployeeEquipmentAssignments` aplicada (`migrate:status`, batch 68).
- Login demo `demo@mantenimiento.local` (se restableció la contraseña de staging al valor documentado `Demo12345`): 303 a `/inicio`.
- Ficha `/mantenimiento/equipos/109`: 200; payload con `unassignUrl`, `current: null` después de la desvinculación y `history` con `closureReason`/`closureNotes`.
- Desvinculación por POST: 303 a la ficha. Datos verificados:
  - Asignación 103 (empresa 7 / equipo 109 / empleado 79): `fecha_hasta = 2026-11-27`, `motivo_cierre = Validacion staging #593`, `observaciones_cierre = Unidad demo para validar desvinculacion`, `updated_by = 16`.
  - Empleado 79: `activo = 1`, sin fecha de baja.
  - Equipo 109: `estado = ACTIVO`.
  - Entrega 5963 (`PENDIENTE`, teléfono dummy): `OMITIDA`, `ultimo_error` = “Cancelada por desvinculación del chofer (asignación #103): …”, `proximo_intento = NULL`.
  - Auditoría 20: `asignacion.chofer_cerrada`, entidad `employee_equipment_assignments`/103, empresa 7, actor 16, motivo, before/after JSON y `notificaciones_canceladas = 1`.
  - 0 asignaciones abiertas y 0 candidatos del scheduler para el equipo 109.
  - Reasignación posterior: nueva asignación 106 abierta para el empleado 79; el historial conserva la 103 cerrada.
- Aislamiento externo: `alerts.webCronEnabled = false`, SMTP vacío y sin tarea programada en el contenedor de validación; la entrega de prueba usó teléfono dummy y fue cancelada. **No se envió ningún WhatsApp ni correo.**
- La asignación real de TSA (97, empresa 4 / NEB021 / empleado 4) **no se tocó**.
- Contenedor temporal y volúmenes eliminados; el staging existente quedó `healthy`.

## Pendiente

1. **Caso TSA**: no cerrar la asignación 97 por SQL. Con la funcionalidad desplegada y la confirmación del administrador, ejecutar la desvinculación por la pantalla con fecha efectiva y motivo. Después, verificar que el siguiente ciclo no selecciona al chofer.
2. **Producción Ferozo**: promover solo con CI verde y esta evidencia de staging. Orden: migración, código, assets; smoke de ficha de equipo y cron.
3. **Datos históricos**: no se corrigen los 4 intervalos inválidos ni la baja con fecha futura. Quedan para una revisión de calidad de datos independiente.
4. **Issues #594 y #595**: no se implementan en este PR.
5. **Cierre del incidente**: no cerrar #593 hasta regularizar la asignación real y comprobar el siguiente ciclo.

## CI

- Run `45995990425` de `Issue 15 check`: **verde** (PHP syntax, PHPUnit, frontend tests, build sync, tooling self-test).
