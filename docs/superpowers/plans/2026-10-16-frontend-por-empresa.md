# Frontends por empresa Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que cada empresa use un perfil de frontend propio, elegido desde su identidad autenticada, con backend, rutas y permisos compartidos.

**Architecture:** CI4 resolverá el perfil de presentación de la empresa mediante el shell autenticado y lo expondrá como DTO. Vue elegirá componentes desde un registro estático compilado y allowlisted; `base` conserva la experiencia existente, `vogel` queda registrado como perfil de marca y cada perfil nuevo del cliente tendrá código propio. La asignación se hará desde Superadministrador y se auditará; ningún perfil decide permisos ni lee datos directamente.

**Tech Stack:** CodeIgniter 4, PHP 8.2+, MariaDB/MySQL, Vue 3, Vite, Vitest, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-16-frontend-por-empresa-design.md`

## Global Constraints

- Mantener la regla de dependencias `Presentation / Infrastructure -> Application -> Domain`; el dominio no importa CodeIgniter, HTTP, Query Builder ni filesystem.
- Mantener un único monolito modular CI4, un backend y un release coordinado de la aplicación y assets Vite.
- Resolver el perfil con el `empresa_id` del usuario autenticado y activo; nunca aceptar la empresa o el perfil como autoridad desde el navegador.
- Los usuarios comunes pertenecen a una empresa; autorización, alcance por empresa/sucursal, CSRF y validaciones siguen aplicándose en servidor.
- `/inicio` continúa como origen autenticado de navegación a módulos; las rutas de módulos y sus permisos no cambian por el perfil.
- `base` es fallback compartido; `vogel` conserva la presentación actual; cada perfil personalizado pertenece a una sola empresa salvo aprobación explícita de compartirlo.
- El código de frontend se versiona y compila. No ejecutar código, HTML, CSS arbitrario ni URL de bundle suministrados por clientes.
- Login y recuperación preautenticación se mantienen compartidos en esta entrega.
- Staging de este proyecto es exclusivamente Docker/Coolify en `fasa_189`; antes de operar allí verificar host, contenedores, compose, stack, puerto y checkout/commit, y preservar volúmenes. No usar `fasa_195`.
- Producción sigue en Ferozo/FTPS, sin SSH ni CLI. Este plan no incluye despliegue de producción.

## Review Focus

- Usuario inactivo, Superadministrador o usuario sin empresa: recibir el perfil de plataforma/fallback correspondiente sin heredar el perfil de un tenant seleccionado (Task 3).
- Clave de perfil malformada, desconocida o alterada en payload/URL: resolver `base`; nunca construir una ruta de importación con la entrada (Tasks 1, 3 y 5).
- Dos empresas intentando compartir por error un perfil personalizado: rechazar la segunda asignación de forma atómica y mantener auditoría consistente (Task 2).
- Perfil personalizado que oculta una acción autorizada o muestra una no permitida: navegación puede variar, pero el filtro HTTP del servidor conserva el resultado de autorización (Tasks 4 y 7).
- Configuración ausente, historial de perfil, bundle/chunk faltante o estilos de otro perfil: conservar fallback y feedback comprensible sin filtrar estado entre tenants (Tasks 2, 5 y 7).

---

### Task 1: Persistir catálogo asignado y auditoría del perfil de empresa

**Files:**
- Create: `app/Database/Migrations/2026-10-16-090000_CreateCompanyPresentationProfiles.php`
- Create: `app/Application/Platform/PresentationProfiles/FrontendProfileCatalog.php`
- Test: `tests/unit/Database/Migrations/CompanyPresentationProfilesMigrationTest.php`
- Test: `tests/unit/Platform/FrontendProfileCatalogTest.php`

**Interfaces:**
- Produces: `FrontendProfileCatalog::find(string $key): ?array` con claves compiladas `base`, `vogel` y metadatos `shareable`; `FrontendProfileCatalog::defaultKey(): string` devuelve `base`.
- La persistencia usa una tabla de configuración por empresa y una tabla append-only de cambios de perfil.

- [ ] **Step 1: Escribir pruebas de contrato de migración y catálogo**

Crear `CompanyPresentationProfilesMigrationTest` para verificar:
- `empresa_configuracion_presentacion` tiene una fila por empresa, `frontend_profile`, autoría y timestamps;
- `perfil_exclusivo` es nullable y único para perfiles personalizados; el perfil compartido `base` lo deja `NULL`;
- `empresa_presentacion_perfil_historial` conserva empresa, perfil anterior/nuevo, actor y fecha, sin operación de edición o borrado en la migración;
- ambas tablas tienen sus claves foráneas e índices esperados; `down()` solo elimina tablas vacías y falla explícitamente si contienen datos para evitar borrar asignaciones o auditoría;
- no se agregan campos ni se reescriben empresas existentes.

Crear `FrontendProfileCatalogTest` para verificar `base`, `vogel`, perfil desconocido y clave vacía. `base` es compartido; `vogel` y perfiles de clientes son exclusivos.

- [ ] **Step 2: Ejecutar las pruebas nuevas y confirmar que fallan**

Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Database/Migrations/CompanyPresentationProfilesMigrationTest.php --no-coverage"`
Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Platform/FrontendProfileCatalogTest.php --no-coverage"`
Expected: FAIL porque migración, tablas y catálogo todavía no existen.

- [ ] **Step 3: Implementar migración y catálogo allowlisted**

Crear `FrontendProfileCatalog` como catálogo de aplicación sin dependencias de CI4. Crear las dos tablas con `empresa_id` único en configuración y `perfil_exclusivo` único nullable para que MariaDB rechace asignaciones simultáneas del mismo perfil no compartible. La tabla de historial se inserta junto con la asignación; el perfil `base` no ocupa la clave exclusiva. `down()` comprueba que ambas tablas estén vacías antes de revertirlas; con datos, interrumpe el rollback sin borrarlos.

- [ ] **Step 4: Ejecutar pruebas de migración y catálogo**

Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Database/Migrations/CompanyPresentationProfilesMigrationTest.php --no-coverage"`
Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Platform/FrontendProfileCatalogTest.php --no-coverage"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Database/Migrations/2026-10-16-090000_CreateCompanyPresentationProfiles.php app/Application/Platform/PresentationProfiles/FrontendProfileCatalog.php tests/unit/Database/Migrations/CompanyPresentationProfilesMigrationTest.php tests/unit/Platform/FrontendProfileCatalogTest.php
git commit -m "feat(plataforma): persistir perfiles de presentación por empresa"
```

### Task 2: Asignar perfiles con autorización, unicidad y auditoría

**Files:**
- Create: `app/Application/Platform/PresentationProfiles/AssignCompanyPresentationProfile.php`
- Create: `app/Application/Platform/PresentationProfiles/Port/CompanyPresentationProfileStore.php`
- Create: `app/Infrastructure/Platform/PresentationProfiles/CodeIgniterCompanyPresentationProfileStore.php`
- Modify: `app/Config/Services.php`
- Test: `tests/unit/Application/Platform/PresentationProfiles/AssignCompanyPresentationProfileTest.php`
- Test: `tests/unit/Infrastructure/Platform/PresentationProfiles/CodeIgniterCompanyPresentationProfileStoreTest.php`

**Interfaces:**
- Consumes: `FrontendProfileCatalog::find()` de Task 1.
- Produces: `AssignCompanyPresentationProfile::execute(ActorContext $actor, int $companyId, string $profileKey): void`.
- `CompanyPresentationProfileStore::assign(int $companyId, string $profileKey, int $actorUserId, bool $exclusive): void` realiza transacción y auditoría.

- [ ] **Step 1: Escribir pruebas de caso de uso y adaptador**

En `AssignCompanyPresentationProfileTest`, cubrir: Superadministrador asigna perfil conocido; actor no global recibe denegación; clave no registrada se rechaza; `base` puede asignarse a varias empresas; perfil exclusivo ya asignado a otra empresa se rechaza; asignar el mismo perfil otra vez es idempotente y no duplica auditoría.

En `CodeIgniterCompanyPresentationProfileStoreTest`, verificar en MariaDB que insertar/configurar perfil y registrar historial ocurren juntos; un error de unicidad revierte la actualización y no agrega historial; empresa inexistente/inactiva no se asigna.

- [ ] **Step 2: Ejecutar las pruebas nuevas y confirmar que fallan**

Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Application/Platform/PresentationProfiles/AssignCompanyPresentationProfileTest.php --no-coverage"`
Expected: FAIL porque handler, puerto y adaptador todavía no existen.

- [ ] **Step 3: Implementar caso de uso y adaptador transaccional**

El caso de uso exige `ActorContext::isSuperAdmin()`, busca el perfil en el catálogo y deriva `exclusive` del metadato del catálogo. El adaptador valida empresa activa, guarda perfil y agrega historial en una sola transacción. Debe convertir violación de `perfil_exclusivo` en un error de aplicación comprensible y no incluir SQL en el mensaje al usuario.

- [ ] **Step 4: Cablear composición y ejecutar pruebas**

Registrar el servicio en `Services.php`. Ejecutar las pruebas unitarias PHP desde WSL y la prueba adaptador contra la base MariaDB aislada de staging en `fasa_189`, después de identificar el stack por SSH y conservar intactos sus volúmenes.

Expected: caso de uso PASS; adaptador PASS y rollback probado ante perfil duplicado.

- [ ] **Step 5: Commit**

```bash
git add app/Application/Platform/PresentationProfiles/AssignCompanyPresentationProfile.php app/Application/Platform/PresentationProfiles/Port/CompanyPresentationProfileStore.php app/Infrastructure/Platform/PresentationProfiles/CodeIgniterCompanyPresentationProfileStore.php app/Config/Services.php tests/unit/Application/Platform/PresentationProfiles/AssignCompanyPresentationProfileTest.php tests/unit/Infrastructure/Platform/PresentationProfiles/CodeIgniterCompanyPresentationProfileStoreTest.php
git commit -m "feat(plataforma): auditar asignación de perfiles"
```

### Task 3: Resolver el perfil desde el shell autenticado

**Files:**
- Modify: `app/Application/AppShell/Port/AppShellReadModel.php`
- Modify: `app/Application/AppShell/GetAppShellContext.php`
- Modify: `app/Infrastructure/AppShell/CodeIgniterAppShellReadModel.php`
- Modify: `app/Presentation/AppShellPayload.php`
- Modify: `app/Config/Services.php`
- Modify: `tests/unit/Application/AppShell/GetAppShellContextTest.php`
- Create: `tests/unit/Infrastructure/AppShell/CompanyPresentationProfileScopeTest.php`

**Interfaces:**
- Consumes: Task 1 catalog and Task 2 persisted assignment.
- Produces: payload `presentation: { profile: string }`; `profile` es `base` para tenant sin configuración y `platform` para Superadministrador global.

- [ ] **Step 1: Escribir pruebas de scope y fallback**

Cubrir empresa autenticada con perfil propio, configuración ausente, clave desconocida alterada en DB, usuario sin empresa e identidad Superadministrador. Las consultas deben filtrar por `ActorContext::companyId()` y no leer el `company_id` de la petición.

- [ ] **Step 2: Ejecutar pruebas nuevas y confirmar que fallan**

Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Application/AppShell/GetAppShellContextTest.php --no-coverage"`
Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Infrastructure/AppShell/CompanyPresentationProfileScopeTest.php --no-coverage"`
Expected: FAIL porque el contexto todavía no incluye `presentation.profile`.

- [ ] **Step 3: Extender el read model y DTO del shell**

El adaptador carga el perfil asociado solo con el `companyId` del ActorContext y comprueba que sea parte del catálogo. `GetAppShellContext` devuelve la clave validada; `AppShellPayload` la ubica en `presentation.profile`. Superadmin mantiene `platform` y no hereda perfil de una empresa elegida en otro panel.

- [ ] **Step 4: Ejecutar regresión del shell**

Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Application/AppShell/GetAppShellContextTest.php --no-coverage"`
Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Application/Platform/GetPlatformModuleCatalogTest.php --no-coverage"`
Expected: PASS; navegación, permisos y filtrado de sucursales existentes permanecen iguales.

- [ ] **Step 5: Commit**

```bash
git add app/Application/AppShell/Port/AppShellReadModel.php app/Application/AppShell/GetAppShellContext.php app/Infrastructure/AppShell/CodeIgniterAppShellReadModel.php app/Presentation/AppShellPayload.php app/Config/Services.php tests/unit/Application/AppShell/GetAppShellContextTest.php tests/unit/Infrastructure/AppShell/CompanyPresentationProfileScopeTest.php
git commit -m "feat(shell): resolver perfil visual desde empresa autenticada"
```

### Task 4: Administrar asignación desde Superadministrador

**Files:**
- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/SuperAdmin.php`
- Modify: `app/Presentation/AdministrationPayload.php`
- Create: `app/Application/Platform/PresentationProfiles/ListCompanyPresentationProfiles.php`
- Modify: `frontend/src/pages/admin/SuperAdminPage.vue`
- Test: `tests/unit/Controllers/SuperAdminCompanyPresentationProfileTest.php`
- Test: `tests/unit/Presentation/CompanyPresentationProfileAdministrationPayloadTest.php`
- Test: `frontend/tests/SuperAdminCompanyPresentationProfile.test.js`

**Interfaces:**
- Consumes: `AssignCompanyPresentationProfile::execute()` y Task 3 payload/configuración.
- Produce: acción POST `superadmin/empresas/{id}/presentacion`, opciones del catálogo y valor actual por empresa. No crea una ruta para administradores de empresa.

- [x] **Step 1: Escribir prueba de ruta/actor y contrato del panel**

Cubrir acceso con filtro Superadmin, POST con CSRF, rechazo de actor tenant, rechazo de perfil desconocido, presentación del perfil actual y envío del perfil elegido. El payload solo enumera perfiles compilados desde catálogo y empresas retornadas por la consulta administrativa vigente.

- [x] **Step 2: Ejecutar pruebas nuevas y confirmar que fallan**

Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Controllers/SuperAdminCompanyPresentationProfileTest.php --no-coverage"`
Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Presentation/CompanyPresentationProfileAdministrationPayloadTest.php --no-coverage"`
Expected: FAIL porque la acción, ruta y payload no existen.

- [x] **Step 3: Implementar acción POST y contrato de administración**

Agregar una ruta solo dentro del grupo `superadmin`, validar el entero de empresa y la clave de perfil, invocar el caso de uso y volver al panel con mensaje accionable. No extender `UpdateCompanyHandler`: la presentación tiene su propio caso de uso y tabla.

- [x] **Step 4: Agregar selector por empresa en el panel**

En la sección existente de Empresas de `SuperAdminPage.vue`, mostrar perfil actual y formulario POST con `CsrfField`. El selector usa las opciones allowlisted, confirma el cambio y conserva filtros/paginación del panel. No permitir URL, JavaScript, CSS o claves libres.

- [x] **Step 5: Ejecutar pruebas PHP y frontend**

Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Controllers/SuperAdminCompanyPresentationProfileTest.php --no-coverage"`
Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit tests/unit/Presentation/CompanyPresentationProfileAdministrationPayloadTest.php --no-coverage"`
Run: `cd frontend && npm test -- tests/SuperAdminCompanyPresentationProfile.test.js`
Expected: PASS; un actor tenant no puede asignar perfil y el formulario tiene token CSRF.

- [x] **Step 6: Commit**

```bash
git add app/Config/Routes.php app/Controllers/SuperAdmin.php app/Presentation/AdministrationPayload.php frontend/src/pages/admin/SuperAdminPage.vue tests/unit/Controllers/SuperAdminCompanyPresentationProfileTest.php tests/unit/Presentation/CompanyPresentationProfileAdministrationPayloadTest.php frontend/tests/SuperAdminCompanyPresentationProfile.test.js
git commit -m "feat(superadmin): administrar perfiles visuales por empresa"
```

### Task 5: Seleccionar shell y páginas mediante registro Vue allowlisted

**Files:**
- Create: `frontend/src/profiles/registry.js`
- Create: `frontend/src/profiles/base/profile.js`
- Create: `frontend/src/profiles/platform/profile.js` (perfil global reservado; no asignable a empresas)
- Create: `frontend/src/profiles/vogel/profile.js`
- Modify: `frontend/src/main.js`
- Modify: `frontend/src/PageHost.vue`
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/ManagerialApp.vue`
- Modify: `frontend/src/components/ApplicationShell.vue`
- Modify: `frontend/src/adapters/dashboardPayload.js`
- Modify: `frontend/tests/dashboardPayload.test.js`
- Create: `frontend/tests/profileRegistry.test.js`
- Create: `frontend/tests/profileMounting.test.js`

**Interfaces:**
- Consumes: `payload.presentation.profile` de Task 3.
- `frontend/src/profiles/registry.js` exporta `loadFrontendProfile(profileKey): Promise<FrontendProfile>`.
- `FrontendProfile` contiene `key`, `shellComponent`, `resolvePage(pageKey, defaults)`, `dashboardComponents` y asset/style references estáticos.
- `base`, `platform` y `vogel` son entradas compiladas; `platform` se reserva para Superadministrador y nunca se asigna desde administración de empresas. Una clave ausente/desconocida resuelve `base`; la URL de un chunk nunca se construye concatenando la clave recibida.

- [ ] **Step 1: Escribir pruebas del registry y mount**

Agregar pruebas que verifiquen `base`, `vogel`, fallback de clave `../../x`, ausencia de import dinámico arbitrario, override de dashboard/page de un perfil sin mutar el map del otro, y shell de fallback si falla la carga del chunk personalizado.

- [ ] **Step 2: Ejecutar pruebas nuevas y confirmar que fallan**

Run: `cd frontend && npm test -- tests/profileRegistry.test.js tests/profileMounting.test.js`
Expected: FAIL porque el registro y el selector por perfil no existen.

- [ ] **Step 3: Crear perfiles base y Vogel con mapa de página estático**

Mover la selección actual de `App`, `ManagerialApp`, `PageHost` y mapas de páginas a resolutores explícitos. `base` conserva el comportamiento por defecto y `vogel` se registra como perfil independiente; un override se declara en su propio módulo. Las pantallas sin override usan explícitamente el resolutor base.

- [ ] **Step 4: Cargar perfil desde bootstrap de Vue**

Normalizar `presentation.profile` con default `base`; hacer `mountMaintenanceDashboard` async para cargar solo el módulo estático registrado y entregar el shell/dashboard resueltos a `PageHost`, `App` y `ManagerialApp`. Mantener `page` y `data` como contratos de cada controlador.

- [ ] **Step 5: Ejecutar pruebas de normalización y composición**

Run: `cd frontend && npm test -- tests/profileRegistry.test.js tests/profileMounting.test.js tests/dashboardPayload.test.js tests/App.test.js`
Expected: PASS; login, Centro de mandos `/inicio`, páginas de módulo y dashboards actuales siguen montando con `base`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/profiles/registry.js frontend/src/profiles/base/profile.js frontend/src/profiles/vogel/profile.js frontend/src/main.js frontend/src/PageHost.vue frontend/src/App.vue frontend/src/ManagerialApp.vue frontend/src/components/ApplicationShell.vue frontend/src/adapters/dashboardPayload.js frontend/tests/dashboardPayload.test.js frontend/tests/profileRegistry.test.js frontend/tests/profileMounting.test.js
git commit -m "feat(frontend): seleccionar presentación desde perfil de empresa"
```

### Task 6: Crear perfil aislado del nuevo cliente para shell y dashboard

**Files:**
- Create: `frontend/src/profiles/cliente_piloto/profile.js`
- Create: `frontend/src/profiles/cliente_piloto/shell/ClientShell.vue`
- Create: `frontend/src/profiles/cliente_piloto/pages/ClientDashboard.vue`
- Create: `frontend/src/profiles/cliente_piloto/styles.css`
- Modify: `frontend/src/profiles/registry.js`
- Test: `frontend/tests/profiles/cliente_piloto/ClientDashboard.test.js`
- Test: `frontend/tests/profiles/cliente_piloto/ClientShell.test.js`
- Conditional: dashboard application/read adapter and tests, only if an approved KPI is absent from the current payload.

**Precondition:** `cliente_piloto` es la clave técnica reservada para esta primera adaptación. Antes de dibujar el dashboard, registrar en el issue/brief y obtener aprobación del orden de indicadores, acciones principales y referencias de escritorio/móvil; recibir logo y colores cuando formen parte del encargo. Guardar el logo entregado dentro de `frontend/src/profiles/cliente_piloto/assets/brand/` solo si el brief incluye uno. La spec no autoriza inventar ese diseño.

**Interfaces:**
- Consume el contrato de dashboard existente `metrics`, `financial`, `charts`, `readingAttention`, `upcomingMaintenance`, `links` y shell autorizado.
- Solo se agrega dato al caso de uso/read model si un indicador aprobado falta; el perfil no consulta DB ni calcula reglas de negocio.
- La clave personalizada debe quedar marcada exclusiva en PHP y en el registro Vue.

- [ ] **Step 1: Escribir pruebas del perfil nuevo y aislamiento de componentes**

Verificar KPI/acciones del brief, enlaces servidos por backend, diseño con datos vacíos, fallback de métricas ausentes sin valores inventados, layout responsive en tests existentes/visual QA y que montar el perfil nuevo no cambia props ni componentes de `base`/`vogel`.

- [ ] **Step 2: Confirmar que las pruebas fallan antes de implementar**

Run: `cd frontend && npm test -- tests/profiles/cliente_piloto/ClientDashboard.test.js tests/profiles/cliente_piloto/ClientShell.test.js`
Expected: FAIL porque el perfil todavía no está registrado.

- [ ] **Step 3: Implementar shell, marca y dashboard del perfil**

Crear componentes aislados bajo `cliente_piloto`. Los estilos se importan solo por el perfil y la marca se lee de assets versionados; no compartir selectores globales que cambien otros perfiles. Los CTA usan `links` autorizados recibidos del servidor.

- [ ] **Step 4: Extender lectura de dashboard solo para datos aprobados que falten**

Para cada KPI aprobado ausente del DTO actual, definir fórmula/fuente en el brief, agregar la consulta mediante puerto y adaptador de lectura y probar empresa, sucursal y permiso. Si el indicador no tiene datos mínimos, presentar “sin datos suficientes”. Omitir cualquier métrica sin definición aprobada.

- [ ] **Step 5: Ejecutar pruebas del perfil y build Vite**

Run: `cd frontend && npm test -- tests/profiles/cliente_piloto/ClientDashboard.test.js tests/profiles/cliente_piloto/ClientShell.test.js tests/profileRegistry.test.js`
Run: `cd frontend && npm run build`
Run: `cd frontend && npm run verify:build-sync`
Expected: PASS; el manifest contiene el chunk del perfil y los bundles sincronizados corresponden al build.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/profiles frontend/tests/profiles frontend/src/profiles/registry.js app/Application/Dashboard app/Infrastructure/Dashboard tests/unit/Application/Dashboard
git commit -m "feat(dashboard): personalizar panel del cliente por perfil"
```

### Task 7: Verificar aislamiento, migración y experiencia en staging

**Files:**
- Modify as needed: `frontend/scripts/visual-qa.mjs`
- Modify as needed: `docs/superpowers/specs/2026-10-16-frontend-por-empresa-design.md`
- Test: full existing PHP and Vitest suites; HTTP verification against the identified `fasa_189` staging stack.

**Interfaces:**
- Consumes completed migration, profile assignment form, shell payload and frontend registry.
- Produces evidence for each supported profile and a rollback/recovery note for the staging release.

- [ ] **Step 1: Ejecutar suites automatizadas de frontend y PHP**

Run: `cd frontend && npm test`
Run: `cd frontend && npm run build`
Run: `cd frontend && npm run verify:build-sync`
Run: `wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit --no-coverage"`
Expected: PASS sin regresiones de permisos, dashboard, Administración, navegación o aplicación de migraciones.

- [ ] **Step 2: Identificar el entorno staging antes de cualquier operación**

Verificar por SSH que el destino sea `fasa_189`; allí inspeccionar `docker ps` y el `docker compose` de Coolify. Confirmar nombre del stack, puerto, checkout/commit y que es staging. Si no se puede verificar o identificar con seguridad, parar y reportar bloqueo. No usar el MariaDB local de Windows ni `fasa_195`.

- [ ] **Step 3: Aplicar migración y pruebas de integración preservando volúmenes**

Aplicar solo la migración del perfil al stack de prueba identificado. Ejecutar prueba de migración desde cero en una base/servicio efímero aislado alojado en `fasa_189`; no reinicializar ni reemplazar los volúmenes de Coolify. Probar asignación desde Superadmin y comprobar en DB que empresa, clave exclusiva e historial quedan consistentes.

- [ ] **Step 4: Verificar HTTP y tenants por separado en staging**

Con dos empresas de prueba autorizadas, asignar perfil exclusivo a una y `base` a otra. Verificar `/inicio`, `/dashboard`, un módulo heredado, menú por permisos, persistencia tras cerrar/abrir sesión y que cambiar perfil del tenant A no modifica el tenant B. Probar perfil inválido y bundle ausente; comprobar fallback y mensajes sin trazas.

- [ ] **Step 5: Registrar evidencia de escritorio y móvil**

Capturar login compartido, shell, dashboard del perfil nuevo, dashboard base y vista heredada en tamaños de escritorio y móvil. Revisar enlaces, scroll, navegación móvil, vacíos y errores. Adjuntar capturas y resultado HTTP como evidencia; no etiquetar aceptación visual sin esas capturas.

- [ ] **Step 6: Confirmar staging limpio y commit final de evidencia si corresponde**

Confirmar que los volúmenes de staging siguen presentes, no hay migraciones inesperadas y la ruta HTTP responde con el commit previsto. Registrar comandos, commit y capturas; no desplegar a producción dentro de este plan.

---

## Handoff de diseño del dashboard

La infraestructura de perfiles puede construirse sin la marca final del cliente. La Task 6 no se considera lista para iniciar hasta contar con el brief visual aprobado. Para no detener las Tasks 1–5, el perfil asignado al cliente permanece en `base` hasta que la Task 6 esté aceptada y desplegada.
