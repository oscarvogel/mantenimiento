# Rediseño del portal `/inicio` y del shell — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Shell nuevo (marco, barra extensible superpuesta, encabezado con "Mi cuenta" y búsqueda de equipos) en toda la app y portal `/inicio` rediseñado con la disponibilidad de la flota.

**Architecture:** Tres entregas sobre la rama `feat/rediseno-inicio-shell`: (1) tokens + shell, (2) layout del portal con los datos de `GetPortalHome`, (3) disponibilidad de flota (regla pura en `Domain/WorkOrders`, caso de uso en `Application/Platform` con puerto de lectura, adaptador Query Builder) y búsqueda de equipos (reusa `ListEquipment`). El controlador solo llama casos de uso.

**Tech Stack:** CodeIgniter 4.7 / PHP 8.2, PHPUnit con SQLite para read models, Vue 3 + Tailwind + `@heroicons/vue`, Vitest.

**Spec:** `docs/superpowers/specs/2026-11-25-rediseno-inicio-design.md`. Maqueta de referencia: `maqueta-inicio.html` (scratchpad de la sesión; no versionada).

## Global Constraints

- Solo tokens semánticos en componentes; pasos de escala solo en superficies de marca (marco, barra lateral).
- Sin colores nuevos. Radios nuevos: `pill` 9999px, `card` 24px, `panel` 32px, `frame` 40px. Sombra: la `shadow-card` existente.
- Contraste: texto 4,5:1, foco y marcas 3:1, en claro y oscuro (`frontend/tests/tokens.contrast.test.js`).
- Celular `< 640px`: sin marco; barra lateral de escritorio desde `lg` como hoy; `MobileTabBar` sin cambios.
- Todo dato de flota y búsqueda: empresa del actor + sus sucursales (`branchIds()` salvo `hasAllCompanyBranches()`) + `equipos.ver`. Superadministrador o sin empresa: nada.
- Sin datos → "Sin datos", nunca 0 inventado. Fechas y números por `frontend/src/ui/format.js`.
- Textos en español rioplatense, lenguaje humano. Commits Conventional en español.
- PHPUnit se corre en el contenedor de `mantenimiento-staging` en `fasa_189` (PHP local no tiene `mbstring`): antes de la primera corrida, seguir los 4 pasos de "Entorno de prueba obligatorio" de `AGENTS.md`. Vitest corre local en `frontend/`.

## Review Focus

1. Equipo con dos OT abiertas, una detenida y otra no → cuenta **una vez**, como Detenido, y la fila muestra la OT detenida (test en Task 6).
2. Usuario limitado a la sucursal A que pide `?sucursal=B` → no ve nada de B; se ignora el filtro (test en Task 6 y Task 8).
3. Empresa sin equipos activos → la dona muestra "Sin datos", sin división por cero (tests en Task 6 y Task 9).
4. Búsqueda con 1 carácter o con `%`/`_` → no consulta con menos de 2 caracteres; los comodines se buscan literales (test en Task 8).
5. OT en `BORRADOR` cuenta como abierta (misma regla que `hasOpenOrders`); OT detenida sin `inicio_detencion` usa `fecha_apertura` como "desde" (tests en Task 5/6).

---

## Entrega 1 — Base visual y shell

### Task 1: Tokens de radio y superficie del marco

**Files:**
- Modify: `frontend/tailwind.config.js` (extend `borderRadius`)
- Modify: `frontend/src/styles.css` (variables `--frame` en claro y oscuro)
- Modify: `frontend/tests/tokens.contrast.test.js`
- Modify: `docs/DESIGN_TOKENS.md`

**Interfaces:**
- Produces: clases `rounded-pill`, `rounded-card`, `rounded-panel`, `rounded-frame`; color `bg-frame` (`rgb(var(--frame) / <alpha-value>)`) = `secondary-900` en claro, `secondary-800` en oscuro.

- [ ] **Step 1: Test que falla** — en `tokens.contrast.test.js` agregar pares por tema: `ink-inverse` sobre `frame` ≥ 4,5; `accent-400` sobre `frame` ≥ 3; `surface-subtle` (lienzo) sobre `frame` ≥ 1,3.
- [ ] **Step 2:** `cd frontend && npx vitest run tests/tokens.contrast.test.js` → FAIL (`--frame` indefinido).
- [ ] **Step 3:** Definir `--frame` en `:root` (`var(--secondary-900)`) y `html[data-theme='dark']` (`var(--secondary-800)`); agregar `frame` a `colors` y los cuatro radios a `borderRadius`. Documentar en la tabla "Mapeo por tema" y una sección "Radios".
- [ ] **Step 4:** Mismo comando → PASS.
- [ ] **Step 5:** `git commit -m "feat(ui): tokens de radio y superficie del marco"`

### Task 2: Barra lateral superpuesta con descripciones en el portal

**Files:**
- Modify: `app/Presentation/AppShellPayload.php:263-290` (`platformNavigation`)
- Modify: `frontend/src/components/ApplicationShell.vue`
- Modify: `frontend/src/components/AppSidebar.vue`, `frontend/src/components/SidebarLink.vue`
- Test: `tests/unit/Application/AppShell/GetAppShellContextTest.php`, `frontend/src/components/AppSidebar.platform.test.js`, `frontend/src/components/ApplicationShell.platform.test.js`

**Interfaces:**
- Produces: cada ítem de `shell.navigation` del portal trae `description: string|null`. Inicio: `'Lo que pasa hoy en la flota'`; módulos: `$module['description']`. Los ítems de `moduleNavigation` no traen descripción.

- [ ] **Step 1: Tests que fallan**
  - PHP `testPlatformNavigationCarriesModuleDescriptions`: el ítem `platform-home` tiene `description === 'Lo que pasa hoy en la flota'` y cada `module-*` la descripción del catálogo.
  - Vitest `AppSidebar.platform.test.js` › "muestra la descripción solo expandida": con `collapsed: false` se ve el texto de `description`; con `collapsed: true` no, y el enlace conserva `aria-label`/`title` con el nombre.
  - Vitest `ApplicationShell.platform.test.js` › "expandir la barra no desplaza el contenido": tras expandir, `.workspace-content` no cambia su clase de margen y la barra expandida tiene la clase `shadow-sidebar`; clic en el fondo (`[data-test=sidebar-scrim]`) y `Escape` la vuelven a colapsar y persisten `'collapsed'` en `sessionStorage['maintenance.desktop-menu']`.
- [ ] **Step 2:** Correr: Vitest local (`npx vitest run src/components`) → FAIL; PHPUnit en fasa_189 (`tests/unit/Application/AppShell`) → FAIL.
- [ ] **Step 3:** Agregar `description` en `platformNavigation`. En `ApplicationShell.vue` el contenido usa siempre el margen de la barra colapsada; expandida, la barra queda encima (`z-40`, `shadow-sidebar`, ancho 17rem) con un scrim transparente `data-test="sidebar-scrim"` que colapsa al clic; `Escape` colapsa. `SidebarLink.vue` renderiza `description` debajo del nombre en texto `white/65`, `text-xs`, sin truncar, solo si no está colapsado.
- [ ] **Step 4:** Mismos comandos → PASS.
- [ ] **Step 5:** `git commit -m "feat(shell): barra lateral superpuesta con descripción de cada módulo"`

### Task 3: Marco y encabezado con "Mi cuenta"

**Files:**
- Modify: `frontend/src/components/ApplicationShell.vue` (marco)
- Modify: `frontend/src/components/AppHeader.vue`
- Create: `frontend/src/components/AccountMenu.vue`
- Create: `frontend/src/components/RouteLines.vue` (SVG decorativo `aria-hidden`, trazos de la maqueta, `opacity` 0,06 claro / 0,07 oscuro)
- Test: `frontend/src/components/AppHeader.test.js`, `frontend/src/components/AccountMenu.test.js`

**Interfaces:**
- Consumes: Task 1 (`bg-frame`, radios).
- Produces: `AppHeader` acepta prop `equipmentSearchUrl: String|null` (default `null`) y slot `search`; si `equipmentSearchUrl` es null no se muestra búsqueda. `AccountMenu` props `{ user: Object, logout: Object|null }`.

- [ ] **Step 1: Tests que fallan**
  - `AppHeader.test.js` › "saluda con iniciales, nombre y empresa": muestra `user.initials` en el avatar, `Hola, {primer nombre}` y `"{company.name} · {company.scopeLabel}"`.
  - › "la campana solo con avisos habilitados": con `notifications.enabled=false` no hay `AppNotificationBell`.
  - › "sin URL de búsqueda no hay buscador".
  - `AccountMenu.test.js` › "abre con el botón Mi cuenta y contiene tema y cerrar sesión": `aria-expanded` pasa a `true`; adentro está `ThemeToggle` y un `form` `method=post` con el `action` y el campo CSRF de `logout`; `Escape` cierra y devuelve el foco al botón.
- [ ] **Step 2:** `npx vitest run src/components/AppHeader.test.js src/components/AccountMenu.test.js` → FAIL.
- [ ] **Step 3:** `ApplicationShell.vue`: desde `sm` envolver barra + contenido en `bg-frame rounded-frame p-4` (sin padding izquierdo donde está la barra) y el contenido en `bg-surface-subtle rounded-panel` con `RouteLines` detrás; bajo `sm`, sin marco. Barra con marca activa amarilla (`accent-400`, 8×18px, borde izquierdo). `AppHeader.vue`: sin borde inferior, sobre el lienzo; izquierda avatar `size-12 rounded-full bg-accent-400 text-secondary-900` + saludo; centro slot `search`; derecha campana (`rounded-full bg-surface-raised shadow-card size-11`) y `AccountMenu` (botón `rounded-pill bg-secondary-900 text-white`, en oscuro `bg-neutral-100 text-secondary-900`). `ThemeToggle` sale del encabezado y entra al menú. El cierre de sesión sigue también en la barra lateral.
- [ ] **Step 4:** Mismo comando y `npx vitest run` completo → PASS.
- [ ] **Step 5:** `git commit -m "feat(shell): marco Vogel y encabezado con Mi cuenta"`

## Entrega 2 — Portal

### Task 4: Layout de `/inicio` con los datos actuales

**Files:**
- Modify: `frontend/src/pages/platform/CommandCenterPage.vue`
- Test: `frontend/src/pages/platform/CommandCenterPage.test.js`

**Interfaces:**
- Consumes: `data` actual (`profile`, `modules`, `indicators`, `attention`, `shortcuts`, `userName`). Prop nueva opcional `data.fleet` (Task 9) — ignorar si falta.

- [ ] **Step 1: Tests que fallan** (perfil `management`):
  - "vencidos y próximos son tarjetas grandes": los indicadores `overdue` y `due-soon` se renderizan en `[data-test=hero-indicator]`, el primero con clase `bg-secondary-900`; `open-orders` y `compliance` en `[data-test=mini-indicator]`.
  - "preventivo sin datos": `compliance.value === null` → texto "Sin datos".
  - "atención como filas con estado en píldora": cada ítem en `[data-test=attention-row]` con su `statusLabel` en una píldora; `href` null → fila sin enlace. Bajo `sm` el motivo y "Ver ficha" se ocultan y la fila entera es el enlace.
  - Perfil `field`: "accesos como píldoras, la primera destacada": `[data-test=shortcut-pill]` × n, la primera con `bg-primary-300`; perfil `management` no tiene píldoras.
  - "módulos en grilla compacta": cada módulo deshabilitado tiene `aria-disabled="true"` y sin `href`.
- [ ] **Step 2:** `npx vitest run src/pages/platform` → FAIL.
- [ ] **Step 3:** Reescribir la plantilla según la maqueta: encabezado "Mantenimiento preventivo" + "Ver planes"; 2 tarjetas `rounded-card min-h-[172px]` con trama de puntos SVG decorativa; fila mini; píldoras `rounded-[18px] h-[58px]` con ícono en círculo; "Requiere atención hoy" con encabezado de columnas (Equipo, Motivo, Estado) oculto bajo `sm`; "Módulos" en grilla `sm:grid-cols-2 xl:grid-cols-3`. Grilla principal `xl:grid-cols-[minmax(0,1fr)_360px]` con la columna derecha vacía hasta Task 9. Quitar el saludo grande (ahora está en el encabezado).
- [ ] **Step 4:** Mismo comando → PASS.
- [ ] **Step 5:** `git commit -m "feat(portal): layout del inicio con tarjetas, píldoras y filas"`

## Entrega 3 — Datos nuevos

### Task 5: Regla de disponibilidad (dominio)

**Files:**
- Create: `app/Domain/WorkOrders/EquipmentAvailability.php`
- Test: `tests/unit/Domain/WorkOrders/EquipmentAvailabilityTest.php`

**Interfaces:**
- Produces: `enum EquipmentAvailability: string { case OPERATIVE = 'OPERATIVO'; case IN_SHOP = 'EN_TALLER'; case STOPPED = 'DETENIDO'; public static function ofOpenOrders(array $orders): self }` donde cada orden es `array{outOfService:bool, stopStartedAt:?string, stopEndedAt:?string}` y solo se pasan órdenes **abiertas**. Además `public static function isStopping(array $order): bool`.

- [ ] **Step 1: Tests que fallan:** sin órdenes → `OPERATIVE`; una orden sin check ni detención → `IN_SHOP`; `outOfService=true` → `STOPPED`; `stopStartedAt='2026-11-20 08:00:00', stopEndedAt=null` → `STOPPED`; detención con fin → `IN_SHOP`; dos órdenes, una detenida → `STOPPED`.
- [ ] **Step 2:** PHPUnit en fasa_189 `tests/unit/Domain/WorkOrders/EquipmentAvailabilityTest.php` → FAIL.
- [ ] **Step 3:** Implementar el enum (sin dependencias de framework).
- [ ] **Step 4:** → PASS.
- [ ] **Step 5:** `git commit -m "feat(ordenes): regla de disponibilidad del equipo"`

### Task 6: Caso de uso y lectura de la disponibilidad de flota

**Files:**
- Create: `app/Application/Platform/Port/FleetAvailabilityReadModel.php`
- Create: `app/Application/Platform/GetFleetAvailability.php`
- Create: `app/Infrastructure/Platform/CodeIgniterFleetAvailabilityReadModel.php`
- Modify: `app/Config/Services.php` (servicio `fleetAvailability`)
- Modify: `docs/CONTRATOS_ENTRE_CONTEXTOS.md` (contrato Registro de activos + Ejecución → Plataforma)
- Test: `tests/unit/Application/Platform/GetFleetAvailabilityTest.php` (puerto falso), `tests/unit/Infrastructure/Platform/CodeIgniterFleetAvailabilityReadModelTest.php` (SQLite, columnas con el tipo real de las migraciones: `equipos.estado` VARCHAR `'ACTIVO'`, `ordenes_trabajo.equipo_fuera_servicio` TINYINT)

**Interfaces:**
- Consumes: `EquipmentAvailability` (Task 5).
- Produces:
  - `interface FleetAvailabilityReadModel { public function rows(int $companyId, ?array $branchIds, ?int $branchId): array; public function branches(int $companyId, ?array $branchIds): array; }`
    - `rows`: una fila por (equipo activo × OT abierta), y una fila con campos de OT en null para equipos activos sin OT abierta: `array{equipmentId:int, code:string, plate:?string, branchName:string, orderId:?int, orderNumber:?string, orderStatus:?string, openedAt:?string, outOfService:bool, stopStartedAt:?string, stopEndedAt:?string, reason:?string}`. Equipo activo = `estado = 'ACTIVO'` y `deleted_at IS NULL`. OT abierta = `estado NOT IN ('FINALIZADA','CANCELADA')`. `reason` = `COALESCE(o.diagnostico, ts.nombre)`. Filtro por `equipos.sucursal_id`.
    - `branches`: `list<array{id:int, name:string}>` de las sucursales permitidas.
  - `GetFleetAvailability::execute(ActorContext $actor, ?int $branchId): ?array` → `null` si superadmin, sin empresa o sin `equipos.ver`; si no:
    `array{total:int, counts:array{operative:int, inShop:int, stopped:int}, stopped:list<Row>, inShop:list<Row>, branches:list, branchId:?int}` con `Row = array{equipmentId:int, label:string, branchName:string, orderNumber:?string, orderStatus:?string, reason:?string, since:?string}`; `label` = `"{code} · {plate}"` o `code`; `since` = `stopStartedAt ?? openedAt` para detenidos, `openedAt` para en taller. Cada lista ordenada por `since` ascendente y cortada en **5**. La fila de un detenido usa su OT detenida más antigua; la de en taller, su OT abierta más antigua. `branchId` no autorizado → se trata como `null`.

- [ ] **Step 1: Tests que fallan** (caso de uso, puerto falso):
  - `testCountsEachEquipmentOnceAndPrefersTheStoppingOrder` (Review Focus 1).
  - `testStoppedSinceFallsBackToOpenedAtWithoutStopStart` (Review Focus 5).
  - `testUnauthorizedBranchFilterIsIgnored`: actor con `branchIds [10]`, `branchId 11` → el puerto recibe `branchId null` y `branchIds [10]` (Review Focus 2).
  - `testReturnsNullForSuperAdminOrWithoutPermission`.
  - `testEmptyFleetReportsZeroTotal`: `total 0`, listas vacías (Review Focus 3).
  - `testListsAreCappedAtFiveOldestFirst`.
- [ ] **Step 2: Tests que fallan** (adaptador, SQLite): equipo de otra empresa excluido; equipo `BAJA` y borrado excluidos; OT `FINALIZADA`/`CANCELADA` excluida; OT `BORRADOR` incluida; equipo sin OT aparece con `orderId null`; `branchIds [10]` no trae equipos de la sucursal 11; `reason` toma `diagnostico` y si es null el nombre del tipo de servicio.
- [ ] **Step 3:** PHPUnit en fasa_189 sobre ambos archivos → FAIL.
- [ ] **Step 4:** Implementar puerto, caso de uso y adaptador (Query Builder, `LEFT JOIN` de órdenes abiertas y `tipos_servicio`); registrar `fleetAvailability` en `Services.php`; escribir el contrato en `CONTRATOS_ENTRE_CONTEXTOS.md`.
- [ ] **Step 5:** → PASS.
- [ ] **Step 6:** `git commit -m "feat(portal): disponibilidad de la flota por empresa y sucursal"`

### Task 7: El portal entrega la flota

**Files:**
- Modify: `app/Application/Platform/GetPortalHome.php` (dependencia `GetFleetAvailability`, clave `fleet`)
- Modify: `app/Controllers/CommandCenter.php` (lee `?sucursal=`, arma `href` de cada fila: `mantenimiento/equipos/{id}`)
- Modify: `app/Config/Services.php` (`portalHome`)
- Test: `tests/unit/Application/Platform/GetPortalHomeTest.php`, `tests/feature/` (test HTTP de `/inicio`)

**Interfaces:**
- Consumes: Task 6.
- Produces: `GetPortalHome::execute(ActorContext $actor, ?int $fleetBranchId = null)`; `data.fleet` en la página: el array de Task 6 con `href` absoluto por fila y `allUrl` = `base_url('mantenimiento/equipos')`, o `null`.

- [ ] **Step 1: Tests que fallan:** `testFleetIsPresentOnlyWithEquipmentPermission` (con `equipos.ver` en perfil `field` y `management`; sin él → `fleet null`; superadmin → `null`). HTTP: GET `/inicio?sucursal=99` con usuario de la sucursal 10 responde 200 y no incluye equipos de la 99.
- [ ] **Step 2:** PHPUnit en fasa_189 → FAIL.
- [ ] **Step 3:** Implementar; `sucursal` no numérico → `null`.
- [ ] **Step 4:** → PASS, incluido `tests/feature/RouteResolutionTest.php`.
- [ ] **Step 5:** `git commit -m "feat(portal): el inicio incluye el estado de la flota"`

### Task 8: Búsqueda de equipos

**Files:**
- Modify: `app/Config/Routes.php` (grupo `mantenimiento`: `$routes->get('equipos/buscar', 'AssetManagement::quickSearch', ['filter' => 'permission:equipos.ver']);` antes de las rutas `equipos/(:num)`)
- Modify: `app/Controllers/AssetManagement.php` (método `quickSearch(): ResponseInterface`)
- Modify: `app/Presentation/AppShellPayload.php` (`equipmentSearchUrl` = `base_url('mantenimiento/equipos/buscar')` solo con empresa y `equipos.ver`)
- Create: `frontend/src/components/EquipmentSearchBox.vue`
- Modify: `frontend/src/components/ApplicationShell.vue` (pasa `equipmentSearchUrl` y monta el buscador en el slot `search`)
- Test: `tests/feature/EquipmentQuickSearchTest.php`, `frontend/src/components/EquipmentSearchBox.test.js`

**Interfaces:**
- Consumes: `ListEquipment::execute($actor, new EquipmentListQuery(query: $q, perPage: 8))`.
- Produces: GET `mantenimiento/equipos/buscar?q=` → JSON `{"items":[{"id":int,"label":"COD · PATENTE","detail":"Sucursal · Tipo","href":url}]}`; `q` con menos de 2 caracteres (tras `trim`) → `{"items":[]}` sin consultar.

- [ ] **Step 1: Tests que fallan**
  - HTTP: sin `equipos.ver` → 403/redirección del filtro; `q=a` → items vacíos; `q=AB` encuentra por patente y por código solo dentro de la empresa y sucursales del actor; `q=%` no devuelve todo (Review Focus 4).
  - Vitest: escribe 2+ caracteres → tras 250 ms pide `?q=`; muestra opciones en `role="listbox"` con el input `role="combobox"` y `aria-expanded`; flechas mueven `aria-activedescendant`; Enter navega al `href` activo; sin resultados muestra "No encontramos equipos con «…»"; error de red muestra "No pudimos buscar. Probá de nuevo."
- [ ] **Step 2:** Correr ambos → FAIL.
- [ ] **Step 3:** Implementar. El adaptador de `ListEquipment` ya usa `like()` de Query Builder (escapa comodines); verificar y, si no, corregirlo ahí.
- [ ] **Step 4:** → PASS (incluido `RouteResolutionTest`).
- [ ] **Step 5:** `git commit -m "feat(equipos): búsqueda rápida de equipos desde el encabezado"`

### Task 9: Panel "Estado de la flota"

**Files:**
- Create: `frontend/src/pages/platform/components/FleetAvailabilityPanel.vue`
- Modify: `frontend/src/pages/platform/CommandCenterPage.vue` (columna derecha)
- Test: `frontend/src/pages/platform/components/FleetAvailabilityPanel.test.js`

**Interfaces:**
- Consumes: `data.fleet` (Task 7).

- [ ] **Step 1: Tests que fallan:**
  - "dona con tres tramos y total al centro": tres `circle` con `stroke` `primary-300`, `accent-400`, `danger`; centro "118" con rótulo "Equipos activos"; leyenda "Operativos 104 · En taller 10 · Detenidos 4"; el SVG es `aria-hidden` y hay un texto accesible equivalente.
  - "sin equipos activos": `total 0` → "Sin datos", sin tramos.
  - "listas Detenidos y En taller": títulos con conteo, filas con `label`, `branchName`, `orderNumber`, y "desde" con `formatRelativeDays`; lista vacía → "No hay equipos detenidos" / "No hay equipos en taller".
  - "selector de sucursal": `select` con "Todas las sucursales" + `branches`; cambiarlo envía GET a `/inicio?sucursal={id}`.
  - Página: `fleet null` → no hay columna y la grilla es de una columna.
- [ ] **Step 2:** `npx vitest run src/pages/platform` → FAIL.
- [ ] **Step 3:** Implementar (`rounded-panel bg-surface-raised shadow-card p-6`; dona r=78, trazo 26, separación 3 entre tramos; bajo `xl` el panel va debajo del contenido).
- [ ] **Step 4:** → PASS; `npx vitest run` completo → PASS.
- [ ] **Step 5:** `npm run build` en `frontend/` (regenera `assets/dashboard`).
- [ ] **Step 6: Verificación real en fasa_189:** desplegar la rama en `mantenimiento-staging`, correr la suite PHPUnit completa y `php spark migrate` (sin migraciones nuevas: no debe cambiar nada); abrir `/inicio` con un usuario de una sucursal y con gerencia, en claro/oscuro y a 1440 px y 390 px; abrir un módulo y comprobar marco, barra y "Mi cuenta". Guardar capturas como evidencia.
- [ ] **Step 7:** `git commit -m "feat(portal): panel de estado de la flota"` y `git commit -m "build(frontend): regenera assets/dashboard"`
