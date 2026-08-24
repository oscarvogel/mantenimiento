# Issue #83 — Dashboard operativo de Órdenes de Trabajo

La navegación principal incorpora **Órdenes de trabajo** para usuarios con permisos de consulta de OT.

La vista `/mantenimiento/ordenes` centraliza:

- KPIs de OT abiertas, emitidas, en proceso, en espera de repuestos, demoradas y finalizadas hoy;
- búsqueda por número de OT, código de equipo o patente;
- filtros por estado, sucursal y responsable;
- prioridad de abiertas antes que cerradas;
- identificación de antigüedad y demora calculada en backend;
- acciones de impresión, inicio y reanudación según estado y permisos.

El read model aplica siempre el alcance de empresa y sucursales del `ActorContext`. Un usuario con `ordenes.mi_trabajo` sin `ordenes.ver` queda además restringido a las órdenes que tenga asignadas.

La regla inicial de demora está centralizada en el read model de órdenes y se considera demorada una OT abierta con 3 o más días desde su apertura. La presentación sólo recibe el indicador y la antigüedad calculada.

Pendientes dentro del mismo issue para iteraciones siguientes: detalle navegable de OT y cierre directo desde el listado con el flujo de cierre existente.
