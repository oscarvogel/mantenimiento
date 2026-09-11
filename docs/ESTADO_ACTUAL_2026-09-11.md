# Estado actual del sistema

## Implementado en este checkout

- Tema claro/oscuro con tokens semánticos, sidebar adaptable y fallback de
  iconos SVG.
- Centro operativo, dashboard con indicadores, count-up en tarjetas relevantes,
  filtros, estados vacíos compactos y acciones rápidas.
- Lecturas, equipos, planes, servicios, órdenes, importaciones, empleados,
  vencimientos y notificaciones con scope de empresa/sucursal.
- Bandeja de solicitudes con búsqueda, filtros y revisión auditada: aprobar,
  postergar, rechazar y agrupar con motivo.
- Catálogo de proveedores y talleres.
- Repuestos/insumos asociados al cierre de una OT, incluyendo cantidad, precio,
  proveedor opcional y límites de garantía por fecha, km u horas.
- Eventos de garantía próximos/vencidos incorporados al motor central de
  notificaciones.
- Build frontend dividido en chunks de vendor, administración, reportes y
  chatbot. El entry principal queda por debajo de 500 kB sin comprimir.

## Pendientes de aceptación

- Smoke completo de PHP + MariaDB/MySQL y migraciones desde una base limpia.
- E2E autenticado de solicitudes, proveedores, repuestos y aislamiento entre
  empresas/sucursales.
- Email y Web Push con SMTP, VAPID y navegador configurados.
- Auditoría visual manual en escritorio, móvil y zoom 200%; los tests y el build
  prueban comportamiento automatizado, no sustituyen esa revisión.
- Chatbot contra el proveedor IA real y verificación del primer mensaje.

## Criterio de mantenimiento

Las reglas de scope se validan en los casos de uso/controladores y en las
consultas de infraestructura. No se agregan credenciales ni datos de prueba a
este documento. Los cambios quedan sin commit hasta una indicación explícita.
