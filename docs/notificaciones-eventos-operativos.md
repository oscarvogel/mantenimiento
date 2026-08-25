# Matriz de eventos operativos de notificaciones

Referencia: issue #142, sub-issue de #7.

| Evento | Condición | Severidad | Destinatario | Entidad / deep link |
|---|---|---|---|---|
| `preventivo.proximo` | El evaluador preventivo devuelve `PROXIMO` | WARNING | Usuarios habilitados por scope/preferencias | Plan / `/mantenimiento/planes?equipo_id=…` |
| `preventivo.vencido` | El evaluador preventivo devuelve `VENCIDO` | CRITICAL | Usuarios habilitados por scope/preferencias | Plan / `/mantenimiento/planes?equipo_id=…` |
| `equipo.sin_lectura` | Equipo activo sin lectura o con última lectura anterior al umbral configurado | WARNING | Usuarios habilitados por scope/preferencias | Equipo / `/mantenimiento/equipos/{id}` |
| `orden.asignada` | OT abierta con `responsable_usuario_id` | INFO | Responsable de la OT | OT / `/mantenimiento/ordenes?orden_id=…` |
| `orden.proxima_objetivo` | `fecha_objetivo` entre ahora y el umbral de próximos días | WARNING | Responsable si existe; si no, resolución normal por scope | OT / `/mantenimiento/ordenes?orden_id=…` |
| `orden.demorada` | `fecha_objetivo` vencida; si no existe, apertura anterior al umbral de demora | CRITICAL | Responsable si existe; si no, resolución normal por scope | OT / `/mantenimiento/ordenes?orden_id=…` |
| `orden.espera_repuestos` | Estado `EN_ESPERA_REPUESTOS` | WARNING | Responsable si existe; si no, resolución normal por scope | OT / `/mantenimiento/ordenes?orden_id=…` |

## Idempotencia

Cada productor usa una `event_key` estable por ciclo lógico:

- preventivos: plan + próximo km/horas/fecha;
- lectura desactualizada: equipo + fecha de última lectura;
- asignación: OT + responsable;
- objetivo próximo: OT + fecha objetivo;
- demora: OT + fecha objetivo o apertura usada como referencia;
- espera de repuestos: OT + estado de espera.

`PublishNotifiableEvent` y la persistencia de notificaciones impiden crear dos veces la misma notificación para el mismo destinatario y `event_key`.

## Eventos bloqueados por modelo actual

No se marcan como implementados hasta disponer de una fuente de verdad suficiente:

- `orden.reasignada`: la OT guarda sólo el responsable actual; no existe historial específico de cambios de responsable que permita identificar una reasignación de forma confiable.
- `solicitud.nueva`, `solicitud.critica`, `solicitud.reasignada`: el módulo/tablas de solicitudes no están disponibles actualmente en el modelo desplegado.
- `garantia.proxima`: no existe actualmente una entidad/módulo de garantías con fecha de vencimiento operativa.
- espera por proveedor/autorización: el dominio de OT sólo modela explícitamente `EN_ESPERA_REPUESTOS`; no se infieren estados inexistentes a partir de texto libre.

Cuando esos módulos o historiales existan, deben publicar a través del mismo motor central y no enviar email directamente.
