-- Reinicio de datos preventivos de prueba.
--
-- Objetivo:
--   dejar todos los equipos sin planes preventivos asignados para poder
--   reconstruirlos desde cero durante la etapa de pruebas.
--
-- Este script NO elimina:
--   - equipos
--   - lecturas de equipos
--   - tipos de servicio / biblioteca preventiva
--   - tareas de mantenimiento
--   - relaciones tipo_servicio_tareas
--   - ordenes de trabajo
--
-- Si existen ordenes de trabajo vinculadas a planes/avisos, las claves
-- foraneas RESTRICT impedirán el borrado. En ese caso no desactivar FK:
-- revisar primero esos datos.

START TRANSACTION;

SELECT COUNT(*) AS planes_antes
FROM planes_mantenimiento;

SELECT COUNT(*) AS avisos_antes
FROM avisos_plan;

SELECT COUNT(*) AS ordenes_vinculadas
FROM ordenes_trabajo
WHERE plan_id IS NOT NULL
   OR aviso_plan_id IS NOT NULL;

-- Los avisos dependen de planes_mantenimiento, por eso se borran primero.
DELETE FROM avisos_plan;

-- Cada fila de planes_mantenimiento representa la asignación de un servicio
-- preventivo a un equipo. Al borrar estas filas, los equipos quedan sin planes.
DELETE FROM planes_mantenimiento;

SELECT COUNT(*) AS avisos_despues
FROM avisos_plan;

SELECT COUNT(*) AS planes_despues
FROM planes_mantenimiento;

COMMIT;
