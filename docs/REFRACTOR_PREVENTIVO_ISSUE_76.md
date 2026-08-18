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

La asignación Equipo ↔ Servicio conserva solamente lo específico del equipo: bases/última realización, estado, observaciones y trazabilidad. Durante el cutover sigue persistida físicamente en `planes_mantenimiento`, pero frecuencia y anticipación ya no deben ser entradas del operador.

## Corte implementado en PR #77

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

Esto permite mantener temporalmente `planes_mantenimiento` sin que continúe siendo fuente de configuración para nuevas asignaciones.

## Siguiente corte

1. hacer que lectura/evaluación de asignaciones existentes hidrate frecuencia desde Servicio, no desde columnas duplicadas del plan;
2. adaptar edición para que sólo permita bases/última realización y observaciones;
3. adaptar cierre de OT para recalcular usando la definición vigente del Servicio;
4. resetear datos preventivos de prueba;
5. retirar columnas de frecuencia/origen de plantilla y tablas de Plantillas;
6. eliminar endpoints/componentes legacy de Biblioteca.

## Invariantes

- un Servicio define qué hacer y cada cuánto;
- una asignación no redefine frecuencia;
- el mismo Servicio activo no puede estar dos veces en el mismo Equipo;
- cualquier criterio configurado vence por el primero alcanzado;
- cierre de OT preventiva actualiza la base de la asignación en la misma transacción;
- tareas y materiales históricos de una OT no cambian si luego se edita el Servicio;
- empresa, sucursal y permisos se validan en servidor.
