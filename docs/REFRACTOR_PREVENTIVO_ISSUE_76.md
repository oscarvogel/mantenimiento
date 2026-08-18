# Refactor preventivo — Issue #76

## Decisión

El dominio preventivo converge a tres conceptos visibles:

1. **Servicio de mantenimiento**: definición reusable completa. Contiene frecuencia, anticipación, prioridad, tareas y materiales/repuestos sugeridos.
2. **Asignación Equipo ↔ Servicio**: indica que un equipo usa un servicio y conserva la última realización/base para calcular el próximo mantenimiento.
3. **OT / Ejecución**: snapshot del trabajo realmente solicitado/realizado; al cerrar una OT preventiva actualiza la base de la asignación.

No se crearán nuevas entidades paralelas para representar lo mismo.

## Decisión de cutover

La Biblioteca preventiva y sus Plantillas se descartan. No se migran sus datos de prueba al modelo nuevo. Los Servicios y sus asignaciones se reconstruyen desde la UI nueva.

Excel queda fuera del P0. Si se retoma, debe escribir sobre el mismo catálogo de Servicios.

## Fuente de verdad

`tipos_servicio` es la definición maestra del Servicio:

- frecuencia km / horas / días;
- anticipación km / horas / días;
- prioridad;
- tareas;
- materiales/repuestos;
- empresa y estado.

La asignación Equipo ↔ Servicio conserva solamente lo específico del equipo: bases/última realización, estado, observaciones y trazabilidad. Durante el cutover sigue persistida físicamente en `planes_mantenimiento`.

## Cortes implementados en PR #77

### Catálogo único

- `/mantenimiento/servicios` crea y edita Servicios sin Excel;
- frecuencia y anticipación pertenecen al Servicio;
- navegación deja de exponer Biblioteca preventiva;
- tareas se administran contra el Servicio y no contra Plantillas.

### Asignación directa desde Equipo

El drawer de equipos pasó de `Agregar planes/plantillas` a **Asignar servicios**:

1. busca servicios activos de la empresa;
2. excluye servicios ya asignados;
3. excluye servicios incompatibles con las lecturas que controla el equipo;
4. muestra frecuencia, anticipación y prioridad sólo como información;
5. solicita únicamente la última realización/base aplicable (km, horas y/o fecha);
6. permite asignar varios servicios en una operación de UI.

El caso de uso de asignación ignora frecuencia/anticipación recibida por formularios legacy y obtiene la definición activa directamente de `tipos_servicio` con scope de empresa. La prioridad también se toma del Servicio.

### Motor de vencimientos

La lectura y evaluación de asignaciones existentes ya toma la configuración actual desde `tipos_servicio`:

- intervalo km/horas/días;
- anticipación km/horas/días;
- prioridad.

La base sigue saliendo de la asignación (`planes_mantenimiento` durante el cutover), pero `proximo_km`, `proximas_horas` y `proxima_fecha` se derivan dinámicamente como `base + frecuencia` y dejan de ser fuente de verdad.

Consecuencia: si se cambia la frecuencia de un Servicio, todas sus asignaciones se recalculan con esa nueva definición sin editar equipo por equipo.

## Siguiente corte

1. adaptar edición de asignación para permitir sólo bases/última realización y observaciones;
2. adaptar cierre de OT para recalcular usando la definición vigente del Servicio;
3. resetear datos preventivos de prueba;
4. retirar columnas de frecuencia/prioridad/próximo objetivo/origen de plantilla de la asignación;
5. retirar tablas y código de Plantillas/Biblioteca;
6. adaptar importación al catálogo único sólo si se decide conservarla.

## Invariantes

- un Servicio define qué hacer y cada cuánto;
- una asignación no redefine frecuencia;
- el mismo Servicio activo no puede estar dos veces en el mismo Equipo;
- cualquier criterio configurado vence por el primero alcanzado;
- cambiar frecuencia/anticipación/prioridad del Servicio impacta sus asignaciones;
- cierre de OT preventiva actualiza la base de la asignación en la misma transacción;
- tareas y materiales históricos de una OT no cambian si luego se edita el Servicio;
- empresa, sucursal y permisos se validan en servidor.
