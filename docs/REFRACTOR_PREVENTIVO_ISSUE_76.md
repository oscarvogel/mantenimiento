# Refactor preventivo — Issue #76

## Decisión

El dominio preventivo converge a tres conceptos visibles:

1. **Servicio de mantenimiento**: definición reusable completa. Contiene frecuencia, anticipación, prioridad, tareas y materiales/repuestos sugeridos.
2. **Asignación Equipo ↔ Servicio**: indica que un equipo usa un servicio y conserva la última realización/base para calcular el próximo mantenimiento.
3. **OT / Ejecución**: snapshot del trabajo realmente solicitado/realizado; al cerrar una OT preventiva actualiza la base de la asignación.

No se crearán nuevas entidades paralelas para representar lo mismo.

## Mapeo del esquema actual

### Se conservan y evolucionan

- `tipos_servicio` → pasa a ser la entidad maestra visible **Servicio de mantenimiento**.
- `tareas_mantenimiento` → catálogo reusable de tareas.
- `tipo_servicio_tareas` → relación Servicio ↔ Tarea.
- `tipo_servicio_materiales` → relación Servicio ↔ material/repuesto sugerido.
- `avisos_plan` → se conserva como aviso de una asignación próxima/vencida mientras el nombre físico `plan_id` siga en compatibilidad.
- `ordenes_trabajo`, `orden_tareas` y relaciones existentes → se conservan; deben seguir guardando el snapshot histórico de la ejecución.

### Se reutiliza como compatibilidad y luego se simplifica

- `planes_mantenimiento` → representa transitoriamente la asignación Equipo ↔ Servicio.

Durante la transición mantiene sus columnas actuales para no romper el motor de vencimientos. Una vez que todos los lectores/escritores tomen frecuencia y anticipación desde `tipos_servicio`, las columnas duplicadas de frecuencia/anticipación y origen de plantilla se retirarán mediante una migración posterior.

### Se retiran después del cutover

- `plantillas_mantenimiento`.
- `plantilla_mantenimiento_items`.
- gateways/casos de uso/UI cuyo único propósito sea materializar plantillas en planes.

No se eliminan en la primera migración para mantener la rama ejecutable y permitir un cutover controlado.

## Fuente de verdad de frecuencia

La fuente de verdad objetivo es `tipos_servicio`:

- `intervalo_km`
- `intervalo_horas`
- `intervalo_dias`
- `anticipacion_km`
- `anticipacion_horas`
- `anticipacion_dias`
- `prioridad`

Debe existir al menos un intervalo válido para que un servicio pueda asignarse como preventivo.

La asignación Equipo ↔ Servicio conserva solamente:

- empresa/equipo/servicio;
- base km/horas/fecha;
- estado activo;
- observaciones específicas;
- trazabilidad.

No habrá overrides de frecuencia por equipo en esta etapa.

## Multiempresa

`tipos_servicio` hoy es global. El modelo objetivo necesita scope explícito por empresa.

La primera migración agrega `empresa_id` nullable de forma transitoria para no inventar una pertenencia de registros existentes. Como los datos preventivos actuales son de prueba, el cutover definitivo podrá resetear esos datos y luego exigir `empresa_id NOT NULL` con unicidad `empresa_id + codigo`.

No se debe exponer a una empresa un servicio perteneciente a otra.

## Estrategia de implementación

### Fase A — Fundaciones sin romper el flujo actual

- agregar a `tipos_servicio` empresa, frecuencia, anticipación, prioridad y auditoría necesarias;
- mantener las tablas/columnas legacy para compatibilidad;
- documentar el modelo y agregar cobertura de migración.

### Fase B — Catálogo único de Servicios (#74)

- CRUD de Servicio de mantenimiento;
- frecuencia/anticipación en el Servicio;
- tareas y materiales dentro del mismo detalle;
- importar Excel al mismo catálogo;
- dejar de presentar `Biblioteca preventiva` como módulo separado.

### Fase C — Asignación directa (#73)

- reemplazar `Asignar plan` por `Asignar servicio`;
- elegir servicios activos de la empresa;
- cargar base/última realización;
- impedir duplicados Equipo + Servicio;
- calcular vencimientos usando definición del Servicio.

### Fase D — Cutover

- adaptar avisos, lecturas rápidas, dashboard y OT al nuevo contrato;
- retirar materialización desde plantillas;
- eliminar tablas de plantillas y columnas duplicadas del plan/asignación;
- actualizar importación, navegación y textos;
- ejecutar/resetear datos preventivos de prueba de forma explícita y documentada.

## Invariantes

- un Servicio define qué hacer y cada cuánto;
- una asignación no redefine frecuencia;
- el mismo Servicio activo no puede estar dos veces en el mismo Equipo;
- cualquier criterio configurado vence por el primero alcanzado;
- cierre de OT preventiva actualiza la base de la asignación en la misma transacción;
- tareas y materiales históricos de una OT no cambian si luego se edita el Servicio;
- empresa, sucursal y permisos se validan en servidor.
