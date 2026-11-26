# Rediseño de interiores, navegación restante y pendientes chicos — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task (sin subagentes, por decisión del usuario). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el interior de cada módulo se vea como la referencia. Antes, cerrar los pendientes de navegación (celular, Viajes, Gerencial) y los detalles chicos de la entrega del inicio.

**Architecture:** Solo frontend, salvo que una pantalla pida un dato que hoy no existe (en ese caso, se para y se consulta). Sobre los tokens que ya existen se arma un set chico de piezas compartidas (tarjeta, panel, botón, píldora de estado, campo, tabla, encabezado de página). Las pantallas no se rediseñan una por una: se agrupan en **5 patrones**, se maqueta cada patrón una sola vez y, con la maqueta aprobada, se aplica a todas las pantallas de ese patrón.

**Tech Stack:** Vue 3, Tailwind (tokens de `frontend/src/styles.css` y `tailwind.config.js`), Vitest; CodeIgniter 4 sin cambios previstos.

**Spec:** `docs/superpowers/specs/2026-11-25-rediseno-inicio-design.md` (lenguaje visual aprobado, referencia y tokens). El interior de los módulos no tiene spec propia: la Tarea 4 la produce, con maquetas, antes de tocar pantallas.

**Rama:** `feat/rediseno-inicio-shell` (decisión del usuario: sigue el mismo rediseño).

## Global Constraints

- Identidad Vogel: paleta marino, azul y amarillo con escalas 100–900, letra Inter y textos humanos en español rioplatense. No aparecen códigos internos.
- Tokens semánticos siempre (`bg-surface-raised`, `text-ink`, `rounded-card`…), nunca colores sueltos. Cada par nuevo de color entra a `frontend/tests/tokens.contrast.test.js`.
- El tema oscuro es equivalente en cada pantalla. En el celular no hay marco.
- Sin subagentes; la revisión la hago yo.
- Cambiar el aspecto no cambia el comportamiento: los permisos, filtros, formularios, enlaces y `data-test` existentes se conservan. Si una prueba se rompe por cambiar una clase, se ajusta el selector, nunca la aserción de comportamiento.
- Cada pantalla se verifica en el navegador en claro, en oscuro, en escritorio y en celular (390 px) antes del commit.
- Commits en español con Conventional Commits, uno por patrón o por grupo de pantallas, más el commit de `assets/dashboard`.

## Review Focus

1. **Tablas largas en el celular:** una tabla de órdenes o equipos con 50 filas no debe desbordar el ancho a 390 px. Va como fila apilada o con scroll horizontal dentro de la tarjeta, nunca en la página.
2. **Estados vacíos y de error:** una pantalla sin datos o con un fallo del servidor tiene que mostrar el mensaje existente con el estilo nuevo, no una tarjeta en blanco.
3. **Formularios con errores de validación:** los mensajes por campo siguen visibles y asociados al campo (`aria-describedby`) después del cambio de estilo.
4. **Textos largos** (patente + código + sucursal, nombres de proveedores): se truncan con `truncate` o hacen salto de línea, sin romper la grilla.
5. **Foco con teclado:** los botones y enlaces nuevos conservan un anillo de foco visible (`focus-visible:ring-border-focus`) en los dos temas.

---

## Parte A — Navegación restante (punto 2)

### Task 1: Revisar y ajustar la navegación del celular, Viajes y Gerencial

**Files:**
- Modify (si hace falta): `frontend/src/components/MobileTabBar.vue`, `frontend/src/components/AppSidebar.vue`
- Test: `frontend/src/components/MobileTabBar.test.js`, `frontend/src/components/AppSidebar.platform.test.js`

- [ ] **Step 1:** Recorrer en el navegador:
  - En el celular (390 px), Mantenimiento con perfil de oficina y con perfil de campo: barra inferior, menú, "Mi cuenta" y la píldora del módulo.
  - En escritorio, Viajes y Gerencial: barra contraída, menú desplegado y la píldora del módulo.
- [ ] **Step 2:** Anotar en el ledger cada problema encontrado, con su captura. Si alguno pide decidir qué accesos mostrar, **se le pregunta al usuario** y no se supone.
- [ ] **Step 3:** Corregir cada problema con su prueba de regresión. Por ejemplo, la barra contraída de Viajes tiene que mostrar íconos fijos sin sumar la página actual, igual que Mantenimiento.
- [ ] **Step 4:** Correr `npx vitest run`. Tienen que pasar todas.
- [ ] **Step 5:** Hacer el commit `fix(shell): …`, compilar y hacer el commit `build(frontend): regenera assets/dashboard`.

## Parte B — Pendientes chicos (punto 5)

### Task 2: Nombres de los íconos sin cortar la barra en pantallas bajas

Hoy la barra contraída no tiene desplazamiento, para que el nombre de cada ícono salga por fuera. En pantallas de menos de unos 650 px de alto, los últimos íconos quedan cortados.

**Files:**
- Modify: `frontend/src/components/SidebarLink.vue`, `frontend/src/components/AppSidebar.vue`
- Test: `frontend/src/components/AppSidebar.platform.test.js`

- [ ] **Step 1: Prueba que falla.** `it('la barra contraída se desplaza y el nombre del ícono no queda recortado')`:
  - Que el `nav` contraído tenga la clase `overflow-y-auto`.
  - Que, al hacer `mouseenter` sobre un ícono, se renderice un único `[data-test="rail-tooltip"]` con `position: fixed` y `top` igual al centro del ícono (`getBoundingClientRect` simulado).
- [ ] **Step 2:** Correrla y ver que falla.
- [ ] **Step 3:** Implementar:
  - Un solo globo por barra en `AppSidebar.vue`, con estado `{ label, top } | null`, posición `fixed` y `left: var(--workspace-sidebar-width)`.
  - Mostrarlo en `mouseenter`/`focusin` del ícono y ocultarlo en `mouseleave`/`focusout`, al desplazar la barra y con `Escape`.
  - Devolverle `overflow-y-auto` al `nav` contraído y quitar el globo por ícono de `SidebarLink.vue`.
- [ ] **Step 4:** Correr `npx vitest run src/components`. Tiene que pasar todo. Verificar en el navegador con una ventana de 600 px de alto.
- [ ] **Step 5:** Hacer el commit `fix(shell): la barra contraída se desplaza en pantallas bajas`.

### Task 3: Fecha en el portal y listas de la flota con datos

**Files:**
- Modify: `frontend/src/components/AppHeader.vue`, `frontend/src/components/ApplicationShell.vue`
- Test: `frontend/src/components/AppHeader.test.js`

- [ ] **Step 1:** La fecha va bajo el saludo del encabezado, solo en el portal (decisión del usuario, 26/11). Se agrega como prop `today` de `AppHeader.vue`, que pasa solo el shell del portal.
- [ ] **Step 2:** Escribir la prueba que falla: la fecha se muestra con `Intl.DateTimeFormat('es-AR', { weekday: 'long', day: 'numeric', month: 'long' })` sobre una fecha fija inyectada.
- [ ] **Step 3:** Implementarlo y hacer que la prueba pase.
- [ ] **Step 4: Listas de la flota con datos, solo en la base local.**
  - Con la app, crear dos órdenes abiertas sobre el equipo de demo: una con "equipo fuera de servicio" y otra en espera de repuestos.
  - Verificar en `/inicio` que aparecen en Detenidos y en En taller, con su OT, el motivo y el tiempo.
  - Hacer una captura.
  - Cerrar o cancelar las dos órdenes. **No se borra nada.**
- [ ] **Step 5:** Hacer el commit `feat(portal): fecha del día` y el de los archivos compilados.

## Parte C — Interiores de los módulos (punto 1)

Hay 65 pantallas. Se agrupan en estos patrones:

| Patrón | Qué es | Pantallas de ejemplo (la primera es la que se maqueta) |
|---|---|---|
| P1 Resumen | Indicadores, gráficos y atención | MaintenanceOverview (Resumen de Mantenimiento), TripOperationsDashboard, TripsDashboard, FleetBoard, TheoreticalCost, Reports |
| P2 Listado | Filtros, contadores y tabla o tarjetas | WorkOrdersIndex, WorkRequestsIndex, PreventivePlans, AssetsIndex, Employees, Expirations, Providers, TireRegistry, TripOperationsIndex, CustomerAccounts |
| P3 Ficha | Detalle de una entidad con pestañas | EquipmentDetail, TripOperationsDetail, TireDetail, CustomerAccountDetail, TripDetail |
| P4 Carga | Formulario de campo o de oficina | QuickReadings, RegisterReading, EquipmentOperate, TripOperationsCreate, WorkOrderDocumentImport |
| P5 Configuración | Maestros, importaciones, usuarios y ajustes | EquipmentCatalogsMaster, ExpirationTypesMaster, TripMasters, TireMasters, ImportsIndex/Show, Users/Branches admin, Integrations, Notifications |

Las pantallas de superadministración (`admin/SuperAdmin*`, `TelemetrySamples`, `WhatsAppDigestPreview`, `AuditChanges`, `NotificationSettings`, `ChatAudit`) entran con el patrón P5, al final de la Task 9. El login queda afuera (decisión del usuario, 26/11).

### Task 4: Piezas compartidas y maqueta de los 5 patrones

**Files:**
- Create: `frontend/src/components/ui/PageHeader.vue` (título, bajada y acciones), `frontend/src/components/ui/StatusPill.vue` (tono `neutral|info|success|warning|danger`), `frontend/src/components/ui/DataPanel.vue` (`rounded-panel`, `shadow-card`, con título y acción opcionales)
- Modify: `frontend/src/styles.css`: en `@layer components`, las clases `ui-btn-primary`, `ui-btn-secondary`, `ui-btn-ghost` (píldora), `ui-field` y `ui-table`
- Create: una maqueta HTML por patrón en el scratchpad, servida con `python -m http.server`
- Create: `docs/superpowers/specs/2026-11-26-rediseno-interiores-design.md`
- Test: `frontend/src/components/ui/*.test.js` y los pares nuevos en `tokens.contrast.test.js`

- [ ] **Step 1:** Maquetar los 5 patrones con la pantalla de ejemplo de cada uno, en claro y en oscuro, y en escritorio y celular.
- [ ] **Step 2: Decisión del usuario.** Aprueba o corrige cada maqueta. Lo aprobado y las decisiones quedan en el spec nuevo.
- [ ] **Step 3:** Escribir las pruebas que fallan de `StatusPill`, `PageHeader` y `DataPanel`:
  - El tono se traduce en las clases de token esperadas.
  - El slot de acciones se renderiza.
  - El título es un `h1` en `PageHeader` y un `h2` en `DataPanel`.
- [ ] **Step 4:** Implementarlos. Agregar las clases `ui-*` y los pares de contraste.
- [ ] **Step 5:** Correr `npx vitest run`. Tienen que pasar todas.
- [ ] **Step 6:** Hacer el commit `feat(ui): piezas compartidas del rediseño`.

**Produces:**
- `<PageHeader title eyebrow? description?>` con un slot `actions`.
- `<StatusPill tone label>`.
- `<DataPanel title? >` con slots `action` y default.
- Las clases `ui-btn-primary | ui-btn-secondary | ui-btn-ghost | ui-field | ui-table`.

### Tasks 5–9: Un patrón por tarea, en este orden

Task 5 es P2 Listado, que es el más usado y el que más se repite. Siguen Task 6 P1 Resumen, Task 7 P4 Carga, Task 8 P3 Ficha y Task 9 P5 Configuración.

Cada tarea repite el mismo ciclo:

- [ ] **Step 1:** Aplicar la maqueta aprobada a la pantalla de ejemplo, usando solo las piezas de la Task 4.
- [ ] **Step 2:** Correr las pruebas existentes de esa pantalla. Si alguna falla por un selector de clase, cambiarla a `data-test` o a rol. Las aserciones de comportamiento no se tocan.
- [ ] **Step 3:** Verificar en el navegador (claro, oscuro, escritorio y 390 px) y revisar los 5 puntos de Review Focus. Mostrarle la captura al usuario.
- [ ] **Step 4:** Hacer el commit de la pantalla de ejemplo.
- [ ] **Step 5:** Aplicar el patrón al resto de las pantallas del grupo, en tandas de 3 a 4 pantallas por commit, con la misma verificación.
- [ ] **Step 6:** Correr `npx vitest run` completo y la suite PHP con `.superpowers/sdd/2026-11-25-rediseno-inicio-shell/phpunit.sh`, y compilar.
- [ ] **Step 7:** Hacer el commit `build(frontend): regenera assets/dashboard`.

### Task 10: Cierre

- [ ] Recorrer la app completa con perfil de oficina y de campo, en claro y en oscuro.
- [ ] Hacer la revisión propia de toda la rama.
- [ ] Actualizar `docs/DESIGN_TOKENS.md` con las piezas compartidas.
- [ ] Verificar en `fasa_189` cuando haya acceso.
- [ ] Abrir el PR.

## Decisiones del usuario (26/11)

1. La fecha del día va bajo el saludo del encabezado, solo en el portal.
2. Las pantallas de superadministración entran; el login no.
3. Se confirma el orden de los patrones: Listado, Resumen, Carga, Ficha y Configuración.
