# Clientes globales compartidos — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer que el importador y Viajes usen el mismo padrón de `clientes` por empresa, preservando los domicilios ya guardados.

**Architecture:** `clientes` sigue siendo el registro canónico creado por el contexto de Importaciones; Viajes opera sobre ese registro mediante su contrato de aplicación y conserva `viajes_domicilios_clientes` como dato propio del módulo. Una migración reproducible copia los clientes Viajes existentes con origen `viajes_legacy`, remapea domicilios y retira la tabla paralela solo luego de verificar las relaciones.

**Tech Stack:** PHP 8.2+, CodeIgniter 4, MariaDB/MySQL, PHPUnit y Docker/Coolify en `fasa_189` para staging.

**Spec:** `docs/superpowers/specs/2026-10-17-clientes-globales-compartidos-design.md`

## Global Constraints

- El Superadministrador global, el scoping por `empresa_id` y los padrones compartidos entre sucursales de una empresa son invariantes.
- La dirección de dependencias es Presentation/Infrastructure → Application → Domain.
- Cada cambio de esquema se entrega mediante migración reproducible; conservar información histórica y permitir rollback seguro cuando sea viable.
- El staging canónico es únicamente `fasa_189` / Docker / Coolify; preservar sus volúmenes.
- Producción Ferozo se despliega por FTPS y no se opera con SSH ni `php spark`.
- No versionar dumps, datos reales, `.env` ni credenciales.

## Review Focus

- Reintentar la confirmación del mismo lote no debe crear clientes duplicados. Prueba de idempotencia en Task 1.
- Un usuario de otra empresa no puede leer ni modificar clientes ajenos. Prueba de scoping en Tasks 2 y 4.
- Un cliente con domicilios existentes debe conservar cada domicilio, país, estado y auditoría. Pruebas de migración en Task 3.
- Un cliente manual de Viajes debe quedar disponible en el padrón común con referencia de origen única. Prueba de creación en Task 2.
- Una sucursal de la misma empresa debe consultar el padrón compartido. Prueba de acceso multi-sucursal en Task 4.

---

### Task 1: Mantener la importación en el padrón canónico

**Files:**
- Inspect: `app/Application/Customers/Port/CustomerImportStore.php` and `app/Infrastructure/Customers/CodeIgniterCustomerImportStore.php`.
- Delete: `app/Application/TripMasters/Port/ImportedCustomerRegistry.php`.
- Delete: `app/Application/TripMasters/SynchronizeImportedCustomers.php`.
- Delete: `app/Infrastructure/TripMasters/CodeIgniterImportedCustomerRegistry.php`.
- Delete: `app/Database/Migrations/2026-10-17-120000_AddImportedCustomerReferencesToTripCustomers.php`.
- Delete: `tests/unit/TripMasters/` tests that exercise the parallel registry.
- Test: customer-import application/infrastructure tests.

**Interfaces:**
- Consumes: approved customer-import rows and existing canonical `clientes` records.
- Produces: a confirmed import that persists only to `clientes`, counts inserted/omitted records there, and creates no customer copy in the Viajes catalog. The existing code already writes to `clientes`; preserve that behavior and remove the untracked parallel synchronization attempt.

- [ ] **Step 1: Write a failing regression test** asserting that accepted customer-import rows and their `(empresa_id, origen, codigo_origen)` references are stored in `clientes`.
- [ ] **Step 2: Run the focused test** with the project PHPUnit runner in WSL and verify the storage contract it asserts.
- [ ] **Step 3: Remove the untracked synchronization-to-`viajes_clientes` files**; do not change the already-canonical importer unless the regression test reveals a defect.
- [ ] **Step 4: Run customer-import tests** and verify confirmation counts canonical insertions and retrying the same source references does not duplicate.

### Task 2: Make Viajes customer operations use canonical `clientes`

**Files:**
- Modify: `app/Application/TripMasters/TripMasterCatalog.php`.
- Modify: `app/Application/TripMasters/Port/TripMasterRepository.php` if the current interface needs explicit company-scoped customer operations.
- Modify: `app/Infrastructure/TripMasters/CodeIgniterTripMasterRepository.php`.
- Modify: `frontend/src/pages/operations/TripMastersPage.vue` only if the shared customer fields/labels need adjustment.
- Test: `tests/unit/TripMasters/`; prepare relevant canonical-customer integration tests for execution after Task 3.
- Acceptance boundary: Task 2 owns its application/adapter tests. The production frontend build, real HTTP flow and desktop/mobile validation are mandatory Task 4 acceptance conditions after the Task 3 contract is integrated.

**Interfaces:**
- Consumes: canonical client IDs, company ownership, name, fiscal identification, telephone, email, active status, and the existing Viajes domicile records.
- Produces: list/search/create/update/inactivate operations scoped to the active company; a new manual Viajes customer uses origin `MANUAL_VIAJES` plus a unique source code in `clientes`.

- [ ] **Step 1: Write failing tests** for listing canonical customers, tenant isolation, manual creation with a unique source reference, and preserving domicile operations.
- [ ] **Step 2: Run the focused tests** and verify they expose reads/writes to `viajes_clientes`.
- [ ] **Step 3: Route customer list and writes through the accepted canonical customer contract**; leave other Viajes catalogs and `viajes_domicilios_clientes` in their existing bounded context.
- [ ] **Step 4: Run TripMasters tests** for the canonical customer read/write contract, company isolation and domicile operations, keeping other catalog behavior intact. Prepare integration scenarios for Task 4; screen acceptance and production build belong to Task 4.

### Task 3: Migrate legacy Viajes customers and domicile references

**Files:**
- Create: `app/Database/Migrations/<timestamp>_MoveTripCustomersToSharedCustomers.php`.
- Modify: `app/Infrastructure/TripMasters/CodeIgniterTripMasterRepository.php` for the new composite FK and joins.
- Test: migration integration tests against MariaDB/MySQL, including `up()` and `down()`.

**Interfaces:**
- Consumes: existing `viajes_clientes`, `viajes_domicilios_clientes`, canonical `clientes` and `empresas`.
- Produces: one new `clientes` row for each legacy Viajes customer, with `origen='viajes_legacy'` and `codigo_origen=<legacy id>`; each domicile points to the corresponding canonical `clientes.id` under the same company; `viajes_clientes` is removed after successful validation.
- Rollback: rebuild `viajes_clientes` from canonical rows carrying the reserved legacy origin and restore domicile IDs; retain canonical rows so rollback cannot discard edits or import history.

- [ ] **Step 1: Write failing migration tests** for row mapping, preserved domicile fields, company-scoped foreign keys, and rollback restoration.
- [ ] **Step 2: Run the migration tests** with the project PHPUnit runner and verify the data mapping and rollback contract before implementation.
- [ ] **Step 3: Implement the migration transactionally where supported**, verify every domicile has a destination before dropping the old FK/table, and make `down()` rebuild from the reserved source references.
- [ ] **Step 4: On `fasa_189` staging only, run the migration up/down/up** and verify customer and domicile counts and values match at each stage; never run the migration against a local or production database.

### Task 4: Verify end-to-end on the canonical staging stack

**Files:**
- Modify: `docs/superpowers/plans/2026-10-17-clientes-globales-compartidos.md` checkboxes/evidence.
- Build: frontend production bundle from `frontend/` with `npm run build` after Tasks 1–3 are integrated; record the result and verify staging serves that bundle.
- Test: staging HTTP flow and database read-only assertions.
- Test: existing customer/domicile view on desktop and mobile with the integrated canonical IDs and domicile FK.

**Interfaces:**
- Consumes: completed Tasks 1–3 and the existing TSA import batch.
- Produces: production-build evidence, desktop/mobile acceptance evidence, and proof that the 356 imported TSA customers are visible in Viajes, the legacy customer and its domicile remain, and tenant/sucursal boundaries hold.
- Acceptance gate: Task 4 cannot close without a successful production frontend build and recorded desktop/mobile validation against the integrated staging contract. An unchanged Vue source does not waive these checks.

- [ ] **Step 1: Re-verify host `fasa_189`, Docker containers, Coolify application/compose, deployed commit, stack and port** before any staging operation; preserve volumes.
- [ ] **Step 2: Build the production frontend bundle** with `npm run build` from `frontend/` after integrating Tasks 1–3; record command, commit and result.
- [ ] **Step 3: Deploy/rebuild only the identified staging stack**, apply migrations there, verify it serves the built bundle, and run the canonical-customer integration scenarios prepared in Task 2; do not operate on `fasa_195` or Ferozo.
- [ ] **Step 4: Verify via the application on desktop and mobile** that the Viajes screen lists/searches the TSA canonical customers, opens their shared fields, permits authorized edit/inactivation, and the legacy customer retains its domicile. Check the customer/domicile list, selection, forms and actions at both viewport sizes, including mobile overflow/usability; record viewport sizes, outcomes and evidence without exposing customer PII.
- [ ] **Step 5: Verify tenant and multi-sucursal cases** and compare read-only row/link counts; do not expose customer PII in logs or final output.
- [ ] **Step 6: Record branch/commit, production build, integration/HTTP and desktop/mobile evidence**; explicitly retain any unverified acceptance condition as pending and keep production deployment as a separate FTPS task.
