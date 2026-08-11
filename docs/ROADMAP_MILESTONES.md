# Roadmap por milestones

Fecha de definición: 11/08/2026

Este documento formaliza los dos hitos principales acordados para el proyecto.

## Milestone 1 — Entrega funcional operativa

### Objetivo

Disponer de una versión operativa del sistema de mantenimiento apta para uso real, cubriendo alta y gestión de equipos, lecturas, mantenimiento preventivo, órdenes, notificaciones y experiencia móvil.

### Issues incluidos

- #1 — Refactor de multi-tenancy: empresa_id en tablas pivote y helper de scoping.
- #4 — Acceso rápido para actualizar km/horas de equipos y disparar preventivos.
- #5 — Foto del equipo/móvil reutilizable en todas las vistas y referencias.
- #6 — Asistente de alta de móviles con planes preventivos sugeridos y base histórica.
- #7 — Motor central de notificaciones: centro interno, preferencias y alertas por email.
- #8 — Web Push/PWA para notificaciones del sistema en navegador y móvil.

### Trabajo ya incorporado

La base operativa principal ya fue incorporada mediante el PR #2, incluyendo acceso multiempresa, activos, lecturas, mantenimiento preventivo, órdenes, importaciones, interfaz, dashboard y reportes.

El PR #3 completa la capacidad de Superadministrador para crear administradores de empresa y debe considerarse parte de la preparación de la entrega funcional mientras permanezca abierto.

### Criterio de cierre

El milestone podrá considerarse completado cuando:

- los issues incluidos estén cerrados;
- el PR #3 o su reemplazo funcional esté integrado;
- la suite automatizada esté en verde;
- exista validación funcional en escritorio y móvil;
- el flujo Equipo → Lectura → Plan → Aviso → OT → Cierre funcione de punta a punta;
- el alta de un móvil usado permita inicializar correctamente sus planes;
- los avisos internos, email y Web Push funcionen según configuración;
- exista una revisión de despliegue/piloto con datos representativos.

---

## Milestone 2 — Chatbot IA integrado

### Objetivo

Agregar un asistente conversacional integrado al sistema que utilice un proveedor de IA configurable mediante API y pueda consultar o ejecutar funciones del sistema mediante tools seguras y auditadas.

El modelo de IA no accede directamente a la base de datos. Todas las operaciones pasan por casos de uso y permisos existentes.

### Flujo objetivo

Usuario → texto/voz → agente IA → tool autorizada → caso de uso del sistema → resultado estructurado → respuesta al usuario

### Issues incluidos

- #9 — Chatbot IA: núcleo conversacional, proveedor configurable y arquitectura de herramientas.
- #10 — Chatbot IA: registrar km/horas/fecha por voz.
- #11 — Chatbot IA: consultar planes de mantenimiento por móvil.
- #12 — Chatbot IA: consultar vencimientos y pendientes operativos.
- #13 — Chatbot IA: catálogo extensible de nuevas capacidades y gobernanza de tools.

### Capacidades iniciales obligatorias

1. Registrar por voz kilometraje, horómetro y fecha de un móvil.
2. Consultar planes de mantenimiento por móvil.
3. Consultar mantenimientos vencidos, próximos y otros pendientes operativos.
4. Permitir incorporar nuevas capacidades mediante tools versionadas sin rehacer el chatbot.

### Seguridad

- API key únicamente en backend/variables de entorno.
- Permisos y alcance por empresa/sucursal validados por el servidor.
- Sin SQL arbitrario ni ejecución de código generada por el modelo.
- Confirmación para escrituras cuando corresponda.
- Auditoría de tool, usuario, parámetros relevantes y resultado.

### Criterio de cierre

El milestone podrá considerarse completado cuando:

- exista chat integrado y responsive;
- proveedor/modelo sea configurable mediante API;
- la API key no llegue al navegador;
- funcionen las tres capacidades iniciales;
- la captura por voz pueda registrar una lectura real reutilizando Measurement;
- las consultas de planes y vencimientos respeten permisos y datos reales;
- exista catálogo extensible de tools con pruebas de contrato;
- las operaciones y errores queden auditados;
- agregar una nueva tool no requiera modificar el núcleo del chatbot.

---

## Orden recomendado

### Entrega funcional operativa

1. Resolver #1 / cerrar cualquier deuda de multi-tenancy y administración.
2. #6 — Alta inteligente y base histórica de planes.
3. #4 — Carga rápida de lecturas.
4. #5 — Identificación visual de equipos.
5. #7 — Motor central de notificaciones.
6. #8 — Web Push/PWA.
7. Validación integral y piloto.

### Chatbot IA integrado

1. #9 — Núcleo y proveedor IA.
2. #13 — Contrato/extensibilidad de tools.
3. #11 — Consulta de planes (READ, menor riesgo).
4. #12 — Consulta de vencimientos y pendientes.
5. #10 — Registro por voz (WRITE, requiere confirmación y mayor validación).

El segundo milestone debe apoyarse en la entrega funcional operativa: el chatbot no reemplaza la lógica del sistema, la consume a través de tools controladas.