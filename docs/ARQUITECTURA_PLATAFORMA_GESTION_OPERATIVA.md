# Arquitectura objetivo — Plataforma Integral de Gestión Operativa

> **Estado:** propuesta de arquitectura para revisión; NO constituye autorización de implementación.
> **Fecha:** 2026-10-08.
> **Repositorio:** `oscarvogel/mantenimiento`.
> **Base de análisis:** rama `main`, inspección inicial del árbol de archivos, navegación, rutas, identidad, permisos y documentación vigente.
> **Alcance:** evolución incremental del producto actual de mantenimiento hacia una plataforma operativa integral.
> **Prioridad normativa:** respetar `AGENTS.md`, `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md`, las decisiones explícitas vigentes y el código ejecutable.
> **Este documento no cambia el alcance de mantenimiento v1.1:** describe una propuesta para capacidades futuras.

## 1. Resumen ejecutivo y decisión

**Decisión propuesta: evolucionar el monolito modular existente, no crear sistemas independientes.**

- Mantener **CodeIgniter 4 + PHP, Vue 3/Vite y MySQL/MariaDB**; un único despliegue por entorno y una sola autenticación.
- Convertir **Mantenimiento** en un módulo de primera clase dentro de una **Plataforma de Gestión Operativa**, sin reescribir su negocio.
- Incorporar un portal de módulos **después del login**; cada usuario ve exclusivamente módulos autorizados.
- Preservar el `ActorContext` existente: usuario normal de una empresa; Superadministrador global, distinto de un usuario operativo.
- Reutilizar equipos, empleados/choferes, mediciones, organización, notificaciones y auditoría mediante **contratos explícitos**, nunca acceso libre entre tablas de contextos.
- Implementar nuevos contextos gradualmente: Viajes → Combustible → Neumáticos → Liquidación/Prefacturación → Costos y Rentabilidad.
- No implementar CQRS, Event Sourcing, brokers, bases independientes ni microservicios por anticipación.
- **Primera entrega ejecutable futura:** portal + navegación contextual + Mantenimiento intacto y sin cambios de negocio.

## 2. Evidencia de arquitectura actual (verificar contra HEAD al ejecutar)

| Zona | Evidencia inspeccionada | Implicación |
|---|---|---|
| Identidad | `app/Application/Identity/ActorContext.php`; `app/Controllers/Login.php` | Ya existe usuario, empresa, sucursales, roles y permisos efectivos; preservar modelo y reuso. |
| Contexto visual | `app/Application/AppShell/GetAppShellContext.php`, `app/Presentation/AppShellPayload.php` | Ya hay shell y navegación generados en backend; evolucionarlos, no duplicarlos en Vue. |
| UI | `frontend/src/components/ApplicationShell.vue`, `AppSidebar.vue` | Se puede introducir selector/portal con reutilización de layout responsive. |
| Inicio actual | `app/Controllers/Home.php`, `Dashboard.php`, `frontend/src/components/GlobalDashboard.vue` | Hoy `/` y post-login llevan a `/dashboard`; ese dashboard mezcla foco operativo y global y no debe confundirse con portal. |
| Rutas | `app/Config/Routes.php` | Existen accesos públicos QR, rutas de mantenimiento, administración, cron y enlaces de compatibilidad. No romperlos. |
| Dominios | `app/Application/{Assets,Employees,Measurement,PreventiveMaintenance,WorkOrders,Notifications,...}` y sus `Domain`/`Infrastructure` | Hay límites de negocio aprovechables; no hacer migración masiva de carpetas. |
| Persistencia | `app/Database/Migrations` (empresas, usuarios/roles, equipos, lecturas, asignaciones, órdenes, notificaciones, vencimientos) | Adoptar nuevas migraciones aditivas; no reescribir migraciones históricas. |
| Despliegue | `AGENTS.md`, `docs/DEPLOY_COOLIFY.md` | Staging = Coolify/Docker en `fasa_189`; producción = Ferozo/FTPS sin CLI ni SSH. |
| Documentación | `docs/PENDING_REFACTOR_MULTITENANCY.md` | Usuario común: una sola empresa; Superadministrador global; aislamiento obligatorio. |
| Restricción conocida | `AGENTS.md` | Arquitectura Clean/Hexagonal/DDD pragmática; evitar colas/eventos por defecto. |

**Nota de alcance de revisión:** el árbol y componentes fundamentales se inspeccionaron, pero no se realizó en esta etapa una auditoría exhaustiva de todas las consultas SQL, migraciones ejecutadas en producción, esquemas reales, contratos de API y escenarios E2E. La fase 0 debe completar ese inventario antes de diseñar tablas o modificar código.

## 3. Principios no negociables

1. **Compatibilidad primero:** funcionalidades y URLs de Mantenimiento siguen operando; no trasladar ni renombrar rutas públicas/QR/notificaciones sin inventario y compatibilidad comprobada.
2. **Una fuente por dato maestro:** equipos, choferes y lecturas no se duplican entre módulos.
3. **Ownership explícito:** cada contexto es dueño de sus invariantes, escrituras y persistencia; otros contextos lo consumen por puerto/caso de uso/read model autorizado.
4. **Aislamiento tenant:** `empresa_id` y, cuando corresponda, sucursal aplicados al construir consultas y comandos; nunca inferir seguridad solo de la interfaz o de una sesión.
5. **Permisos de dos niveles:** módulo habilitado y permiso de acción; ambos se validan en servidor.
6. **Operador primero:** pocas pantallas, tareas concretas, continuidad y mensajes útiles; no obligar a pasar por portal a quien tiene un único módulo.
7. **Trazabilidad:** estados, rectificaciones, anulaciones, comprobantes, cambios de tarifa y costos requieren autor, fecha, origen, motivo y permisos adecuados.
8. **Valores financieros históricos:** moneda, escala, importe DECIMAL, vigencia tarifaria y reglas de imputación quedan congelados para operaciones ya aprobadas.
9. **Privacidad de evidencias:** documentos/fotografías en almacenamiento privado o servidos con controlador autorizado; nunca URL pública por accidente.
10. **Incrementalidad:** cada PR pequeño, con test de contratos, permisos, migración aditiva y rollback operativo factible.

## 4. Mapa de bounded contexts y ownership

| Contexto | Dueño de | Consume de | NO debe hacer |
|---|---|---|---|
| Organización/Identidad | Empresas, sucursales, usuarios, roles, permisos y sesiones | — | Permitir operaciones tenant por ser superadmin sin contexto específico autorizado. |
| Registro de activos (`Assets`) | Equipos, estado, relaciones, sucursal e historial | Organización | Replicar ficha de equipo por cada nuevo módulo. |
| Empleados (`Employees`) | Empleados, estado y asignaciones vigentes | Organización, Activos | Confundir asignación laboral/equipo con asignación histórica a un viaje. |
| Medición (`Measurement`) | Lecturas, origen, evidencia, validación, corrección y última válida | Activos | Aceptar retrocesos o lecturas de viajes/cargas por escritura directa. |
| Mantenimiento | Planes, solicitudes, órdenes, servicios, materiales y costos de mantenimiento | Activos, Medición, Empleados | Ser dueño de viajes, cargas de combustible o facturación. |
| Viajes (nuevo) | Planificación, ejecución, hitos, carga, cliente operativo, cierre y incidencias | Activos, Empleados, Medición, catálogos comerciales | Modificar directamente `equipos.km_actual` ni crear facturas fiscales. |
| Combustible (nuevo) | Cargas, tanques/mediciones si aplican, comprobantes, validación, consumo | Activos, Medición, Viajes cuando proceda | Sobrescribir lecturas por inconsistencias detectadas. |
| Neumáticos (nuevo) | Identidad individual, stock, posiciones, montaje, desmontaje, recapado y baja | Activos, Medición, proveedores | Guardar historial del neumático solo en equipo actual. |
| Comercial / Tarifas (nuevo) | Clientes operativos, contratos, precios y vigencias | Organización | Modificar precios retrospectivamente sin versión. |
| Liquidaciones y Facturación (nuevo) | Elegibilidad, prefactura, detalle, anulaciones, referencia fiscal e integración externa | Viajes, Comercial | Duplicar servicios ya liquidados; asumir que factura fiscal = prefactura. |
| Costos y Rentabilidad (nuevo) | Reglas de imputación, modelos de cálculo y métricas derivadas | Todos por contratos de lectura | Reescribir costos fuente o presentar estimados como reales. |
| Notificaciones / IA / Auditoría | Transporte, trazabilidad, herramientas seguras, alertas | Contextos a través de casos de uso | Ejecutar SQL arbitrario o saltarse validaciones y permisos. |

**Distinción crucial:** `Cliente` para un viaje no es necesariamente `Proveedor` de mantenimiento; evaluar un catálogo comercial propio antes de reutilizar una tabla con semántica distinta. Tampoco confundir remito, evidencia, comprobante de carga, prefactura y factura fiscal.

## 5. Dependencias y contratos

```text
Presentation (CI4 controllers / Vue pages / HTTP / cron / chatbot tool)
                 |
                 v
Application (casos de uso, autorización, puertos, transacciones)
                 |
                 v
Domain (entidades, políticas, invariantes, value objects)
                 ^
                 |
Infrastructure (CI4 DB, archivos, SMTP, WhatsApp, proveedores IA, integraciones)
```

### 5.1 Integraciones iniciales sugeridas (contratos, NO APIs implementadas)

| Consumidor | Proveedor | Contrato funcional | Consistencia |
|---|---|---|---|
| Viajes | Activos | Obtener equipo operativo, pertenencia, estado y capacidades | Validación síncrona antes de asignación. |
| Viajes | Empleados | Validar chofer habilitado y asignaciones | Síncrona al planificar y despachar. |
| Viajes | Medición | Registrar lectura asociada al inicio/fin con origen y evidencia | Caso de uso transaccional o coordinación explícita. |
| Combustible | Medición | Validar y registrar odómetro independiente de la carga | No transformar carga en lectura automáticamente sin política definida. |
| Neumáticos | Medición | Obtener historial km confiable por intervalos de montaje | Lectura autorizada; sin escrituras. |
| Prefacturación | Viajes | Consultar viajes cerrados y elegibles, referencias y datos tarifables | Consistencia fuerte en confirmación e idempotencia. |
| Gerencial | módulos fuente | Read models tipados con fecha de corte y calidad de datos | Lecturas; eventual si el reporte lo admite. |
| IA | Todos | Herramientas tipadas que invocan casos de uso autorizados | Confirmación para operaciones sensibles. |

No crear eventos ni outbox en fase 1. Si se requiere una proyección asíncrona o integrar un tercero, justificar consistencia eventual, reintentos e idempotencia antes de introducir ese mecanismo. En particular, cierres de OT y lecturas acopladas a planes deben conservar las transacciones existentes.

### 5.2 Contrato de datos para trazabilidad cruzada

En registros nuevos que sean tenant-owned:

- `id`, `empresa_id`, referencia(s) por ID a otros contextos, estado, fechas operativas y de sistema.
- `created_by`, `updated_by` y campos de auditoría donde sean relevantes.
- Origen de datos (`manual`, `importacion`, `whatsapp`, `ia`, `integracion`) y referencia de origen para deduplicación.
- `external_reference`/idempotency key si ingresa de otros sistemas; unicidad y ámbito por empresa/operación.
- Evitar borrado físico de transacciones cerradas; corrección como reversión/nueva versión con motivo.
- Dinero como DECIMAL + moneda + vigencia/tipo de costo; nunca `FLOAT`.

### 5.3 Estados sugeridos para discutir con operadores

- **Viaje:** borrador → planificado → asignado → en curso → finalizado → validado; cancelado y observado como salidas controladas.
- **Carga de combustible:** capturada → pendiente de validación → validada; observada/anulada por decisión auditada.
- **Liquidación:** borrador → calculada → revisada → aprobada → exportada/facturada; anulaciones/reemisiones bajo autorización.

Estos estados son **hipótesis funcionales**. Validarlos con casos reales antes de cerrar diseño de tablas o UI.

## 6. Experiencia de usuario y rutas

### 6.1 Flujo recomendado

```text
/login (único)
    ├─ usuario común con un único módulo → módulo autorizado (opción de acceso directo)
    ├─ usuario con varios módulos → /inicio (portal)
    └─ superadmin → administración global (sin suplantar permisos tenant)
                              |
                              +--> /mantenimiento (dashboard y menú propios)
                              +--> /viajes
                              +--> /combustible
                              +--> /neumaticos
                              +--> /facturacion
                              +--> /gerencial
```

**El portal va DESPUÉS del login.** No añadir una pantalla pública con módulos de la empresa ni un selector de empresa para todos. El selector ya existente de filtros de dashboard superadmin no equivale a cambiar identidad o tenant operativo.

### 6.2 Rutas y compatibilidad

- Nueva ruta propuesta `/inicio`: portal, autorizada.
- Mantener `/dashboard` y `/mantenimiento/**` como URLs preexistentes; definir su comportamiento preciso con pruebas de regresión antes de cambiar el destino post-login.
- Mantener vínculos QR, rutas de lectura pública, notificaciones de WhatsApp/email, cron, importaciones y deep links.
- Las nuevas `/viajes/**`, `/combustible/**`, `/neumaticos/**`, `/facturacion/**`, `/gerencial/**` son **espacios reservados**, no rutas implementadas.
- No renombrar el prefijo de instalación en Ferozo ni inventar un subdominio nuevo.
- Las pantallas `Equipos`, `Empleados`, `Control de lecturas` podrán presentarse como maestros transversales sin cambiar inmediatamente sus URL/propietarios técnicos.

### 6.3 Contrato del portal y navegación

El backend debe entregar un **catálogo de módulos visible** y la navegación del módulo activo, derivados de `ActorContext`, habilitación por empresa y permisos:

```json
{
  "activeModule": "maintenance",
  "modules": [
    {
      "key": "maintenance",
      "label": "Mantenimiento",
      "enabled": true,
      "canEnter": true,
      "landingUrl": "/mantenimiento"
    }
  ],
  "navigation": [],
  "homeUrl": "/inicio"
}
```

*Ejemplo ilustrativo de estructura, no payload vigente ni contrato definitivo.* No incluir módulos futuros como disponibles antes de que sus endpoints y permisos existan.

### 6.4 Opciones de módulos y permisos

- Registro de módulos en aplicación (catálogo estable `key`, nombre, destino, orden), con habilitación por empresa **solo si existe necesidad real**. Se puede comenzar con configuración estática: Mantenimiento habilitado, los demás no visibles.
- **Permiso de entrada** al módulo no sustituye los permisos actuales de acciones; deben cumplirse los dos.
- En caso de ausencia de acceso: mensaje 403 amigable y estado HTTP real; no redirección silenciosa ni enlaces rotos.
- Las áreas comunes (equipos/choferes/notificaciones) deben habilitar acceso contextual con permisos de lectura apropiados, no regalar `equipos.editar` por estar en Viajes.
- Superadmin conserva espacio global; el acceso a datos tenant requiere flujo explícito, scopes y auditoría.
- UI responsive: portal de tarjetas para varios módulos y retorno visible a Inicio; menú lateral **del módulo activo**, no un único listado de 50 opciones.

## 7. Datos nuevos: entidades candidatas, NO migraciones aprobadas

Antes de crear tablas, documentar modelos y reglas mediante ejemplos reales de empresa:

| Contexto | Entidades candidatas | Preguntas de diseño abiertas |
|---|---|---|
| Viajes | Viaje, tramo/hito, asignación, carga, incidencia, documento | ¿Simple ida/vuelta, varios destinos, varios acoplados, cambios de chofer, viajes interempresa? |
| Comercial | Cliente, punto de carga/descarga, contrato, tarifa/versiones | ¿Tarifa por km, tonelada, m³, hora, viaje o combinación? ¿Un mismo cliente factura distintas razones sociales? |
| Combustible | Carga, proveedor/estación, comprobante, tanque | ¿Carga en surtidor propio, ticket externo, carga parcial, vales, tanque fijo, odómetro ausente? |
| Neumáticos | Neumático, especificación, posición/eje, movimiento, intervención | ¿Inventario propio, recapado, permutas de equipos, neumáticos gemelos, unidades auxiliares? |
| Prefacturación | Liquidación, línea, regla tarifaria aplicada, referencia a factura | ¿Quién aprueba? ¿Cómo se agrupan viajes por cliente, período, contrato? |
| Costos | Imputación de costo, criterio de distribución, snapshot | ¿Costo directo por viaje o costo mensual de flota? ¿Incluye sueldos, peajes, amortización, seguros? |

**No** inventar aquí nombres de tablas definitivos. Verificar nombres ya existentes, índices, relaciones y convenciones reales para evitar colisiones y duplicados.

## 8. Modelos y cálculos gerenciales

La rentabilidad requiere distinguir:

1. **Ingreso facturable:** calculado bajo una versión de contrato/tarifa y sujeto a validación.
2. **Costo directo:** combustible de un viaje, peajes, viáticos u otros gastos vinculados.
3. **Costo de operación distribuido:** mantenimiento, neumáticos, depreciación, remuneraciones o seguros asignados por regla documentada.
4. **Margen operativo estimado o consolidado:** ingreso menos costos incluidos, informando explícitamente cobertura y faltantes.

No comparar importes de monedas distintas sin tipo de cambio identificado y fecha. No reescribir históricos cuando se modifica una tarifa o criterio. Los dashboards deben exponer período, fecha de actualización, cobertura de datos y diferencias entre costo real/estimado.

## 9. Integración con el agente IA

El chatbot actual puede transformarse en asistente transversal **sin acceso SQL directo**.

- Catálogo de herramientas explícitas por módulo (`consultar_viajes`, `resumen_combustible`, `ver_costos_equipo`, etc.), con entradas/salidas tipadas.
- Cada tool reutiliza `ActorContext`, permisos por módulo y acción, política de empresa/sucursal y casos de uso existentes.
- Lecturas primero; escrituras solo con confirmación, validación de evidencia, idempotencia y auditoría.
- La proactividad debe basarse en reglas/tareas con umbrales definidos (por ejemplo, consumo atípico o viaje pendiente de validar), evitando acciones financieras automáticas sin autorización.
- No se otorga al modelo capacidad de acceso global por administrar el sistema.

## 10. Despliegue, operación y compatibilidad

| Entorno | Regla |
|---|---|
| Staging | `fasa_189`, Docker/Coolify y base separada; nunca base de producción. |
| Producción | Ferozo, FTPS sin SSH/CLI; cambios quirúrgicos con hashes y smoke test. |
| Migraciones | Aditivas y versionadas; no editar migraciones ya aplicadas; asegurar procedimiento web seguro de migración disponible. |
| Frontend | Bundle Vite/manifest sincronizados; no desplegar manifest antes de sus assets. |
| Archivos | Persistencia privada compatible con ambos entornos; validar lectura de evidencias en ambos. |
| Cron | Mantener compatibilidad de notificaciones y jobs existentes; nuevas tareas con idempotencia y credenciales protegidas. |

**Estrategia de entrega:** portal primero, sin migraciones ni datos de prueba en producción; desarrollo posterior de módulos con flags/habilitación y pruebas reales en staging. Cualquier migración de rutas o datos exige plan de reversión específico.

## 11. Hoja de ruta por incrementos

### Fase 0 — Contratos y diseño (sin código)

- Inventario ejecutable de rutas, permisos, modelos, migraciones, consumidores de `/dashboard`, enlaces QR/notificaciones y reportes.
- Mapa de propiedad de datos (`Assets`, `Measurement`, `Employees`, otros) y reglas de convivencia.
- Validación funcional con operadores: circuitos de viaje, carga, neumático, tarifa, prefactura; excepciones reales.
- Decisiones registradas (ADR) para portal post-login, modelo de módulos y redirecciones.

**Salida:** especificación revisada, matriz de permisos, diagramas de flujo, backlog aprobado.

### Fase 1 — Shell/plataforma, sin tocar Mantenimiento

- Portal `/inicio` después del login para usuarios con varios módulos; entrada directa opcional para único módulo.
- Selector contextual de módulos y menú del contexto actual.
- Mantenimiento sigue operativo y todas las rutas antiguas funcionan.
- Contratos de módulos con permiso y alcance; pruebas de usuario común/superadmin y móvil.

**Salida y gate:** aceptación visual escritorio/móvil, suite verde y staging conforme.

### Fase 2 — Viajes y catálogos comerciales mínimos

- Clientes y destinos mínimos, tarifas versionadas si realmente son necesarias en el MVP.
- Circuito de viaje: planificar → asignar → ejecutar → finalizar → validar.
- Registrar evidencias y lecturas por casos de uso de Medición; no duplicar kilometrajes.
- Prefacturación simple opcional como vista provisional sin emitir factura fiscal.

**Gate:** viaje completo validado, cambios de chofer/equipo trazados y pruebas multiempresa.

### Fase 3 — Combustible

- Carga con evidencia, revisión, asociación a equipo/chofer/viaje cuando aplique.
- Detección de errores de odómetro y consumos, sin corregir ni cancelar automáticamente.
- Reporte por equipo/período y alertas configuradas.

**Gate:** conciliación de ejemplos reales y cobertura de cargas sin viaje.

### Fase 4 — Neumáticos

- Identidad única, movimientos con intervalos temporales, posiciones y reparaciones.
- Costo por neumático y km bajo reglas de uso verificadas.

**Gate:** reconstrucción del historial de un neumático montado en más de un equipo.

### Fase 5 — Liquidación, prefacturación y facturación

- Contratos/tarifas con vigencia; liquidación reproducible, no duplicada.
- Validación y aprobación diferenciadas; integración fiscal/contable solo tras decisión explícita.

**Gate:** cierre y reversión seguros; trazabilidad hasta documento real.

### Fase 6 — Analítica/IA transversal

- Costos directos/distribuidos por criterio transparente; rentabilidad por equipo, viaje, período y cliente.
- Herramientas IA y alertas proactivas supervisadas.

**Gate:** conciliación con registros fuente y señalización de calidad del dato.

## 12. Criterios de aceptación por plataforma

- No se pierde acceso a ninguna función actual autorizada de Mantenimiento.
- Una cuenta tenant jamás accede a operaciones/datos de otra empresa; se prueban casos de lectura, creación, modificación y documentos.
- Superadministrador no hereda permisos de operación empresarial de forma implícita.
- Portal muestra solo módulos habilitados **y autorizados**; manipular la URL no permite saltar permisos.
- Un solo login; sin segunda sesión por módulo.
- Deep links, QR públicos y cron existentes conservan su comportamiento.
- Un equipo conserva una sola identidad entre viajes, medición, mantenimiento, combustible y neumáticos.
- Rechazos de valores de kilometraje y correcciones muestran motivos legibles y conservan evidencia/historial.
- No se duplican viajes liquidados o documentos por reintentos.
- No se altera la base productiva en fase 0/1; los nuevos módulos se prueban en staging y con migraciones aditivas.
- Test automatizado + aceptación visual móvil/escritorio + smoke en staging; producción solo bajo aprobación independiente.

## 13. Backlog propuesto (no crear issues aún)

| Orden | Issue candidato | Dependencias |
|---|---|---|
| A0 | Auditoría de contratos/rutas/permisos/consumidores y esquema real | Ninguna |
| A1 | ADR de portal, compatibilidad de dashboard y módulos | A0 |
| A2 | Diseño de matriz de habilitación de módulos y permisos | A0 |
| A3 | UX responsive del portal y selector de contexto | A1, A2 |
| A4 | Backend `ModuleCatalog` + navegación contextual | A1, A2 |
| A5 | Integración de portal, compatibilidad y test de regresión | A3, A4 |
| V1 | Taller funcional y modelo de viaje con excepciones | A0 |
| V2 | Contratos Activos/Empleados/Medición para Viajes | V1 |
| V3 | Circuito de viaje + validación y auditoría | V2, A5 |
| C1 | Reglas y circuito de combustible | V1/contratos de Medición |
| N1 | Modelo de neumático, posición y ciclo de vida | Activos/Medición |
| F1 | Motor de tarifas versionadas y liquidación | V3 + Comercial |
| G1 | Modelo de costos y reportes de rentabilidad | C1, N1, F1 |
| IA1 | Tools seguras por módulo y alertas supervisadas | Contratos disponibles |

Separar funcionalidades de diseño, implementación, migración, tests y despliegue cuando el trabajo real lo justifique. No mezclar módulos en un PR gigante.

## 14. Decisiones pendientes (requieren validación operativa)

1. **Nombre comercial del producto:** nombre actual vs nueva marca.
2. **Portal por defecto:** usuarios con único módulo → acceso directo; confirmar excepciones.
3. **Matriz multiempresa:** si una empresa contrata/habilita módulos selectivamente, y cómo se administran.
4. **Viajes:** definición operativa real (remitos, múltiples tramos, clientes, asignación de cargas, unidades).
5. **Kilometraje:** fuente y política de precedencia entre QR, chofer, viaje, combustible y OCR/IA.
6. **Combustible:** método de medición, tickets, surtidor propio y comparación litros/odómetro.
7. **Neumáticos:** identificación física y costo histórico.
8. **Facturación:** alcance exacto (prefactura, exportación, factura ARCA, cobranzas o nada de esto en MVP).
9. **Costos:** distribución, período, moneda, fuentes y responsables.
10. **Evidencias:** tamaños, retención, persistencia privada entre Docker y Ferozo.

## 15. Límites de esta entrega

Este archivo es **arquitectura propuesta**, no diseño de tablas aprobado ni especificación exhaustiva de nuevos módulos. No se crean ramas de implementación, migraciones, rutas, controladores, UI, flags, permisos, cron ni cambios de despliegue a partir de este documento. La siguiente acción es revisar y aprobar decisiones pendientes, iniciar la fase 0 y recién entonces generar issues implementables.
