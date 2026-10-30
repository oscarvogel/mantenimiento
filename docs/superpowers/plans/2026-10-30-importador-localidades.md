# Importador de localidades Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import once and maintain one shared, official locality catalog for Argentina, Brazil, Chile, and Uruguay, with source codes and coordinates.

**Architecture:** Keep the geography catalog global and outside tenant ownership. A normalizer converts each official country file into a common CSV; an application use case validates/previews and confirms rows through an application port; a CI4 adapter persists an idempotent batch transactionally. A globally authorized admin screen uploads the normalized file. Existing trip search reads the shared catalog.

**Tech Stack:** CodeIgniter 4, PHP 8.2+, MariaDB/MySQL, existing CSV/HTTP patterns, Python 3 standard library for source-package normalization.

**Spec:** `docs/superpowers/specs/2026-10-30-importador-localidades-design.md`; `docs/ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md` §10.2.

## Global Constraints

- Keep dependencies pointing `Presentation / Infrastructure -> Application -> Domain`.
- Do not edit an already-applied migration; add a safe corrective migration.
- Preserve historic locality records and every existing trip; never delete catalog rows during import.
- The shared locality identity must not include `empresa_id`.
- Persist decimal coordinates and do not calculate or overwrite actual trip kilometers.
- Production is Ferozo/FTPS and has no SSH or CLI; catalog loading must work through the protected web screen.
- Do not access `fasa_195`; staging is exclusively `fasa_189` / Coolify.

## Review Focus

- Same locality name in multiple municipalities within one state: preserve source identities and show the administrative context in search results.
- Same file imported twice: do not duplicate rows; report unchanged rows.
- Missing or reused source codes: reject those rows in preview with actionable row errors.
- Invalid coordinates, encoding, or oversized upload: reject before persistence and leave the catalog unchanged.
- Existing localities referenced by trips: update metadata in place; never delete/recreate their IDs.

---

### Task 1: Normalize downloaded official catalogs

**Files:**
- Create: `scripts/normalize_locality_catalogs.py`
- Create: `tests/python/test_normalize_locality_catalogs.py`
- Create: `data/catalogos-localidades/normalizados/argentina.csv`
- Create: `data/catalogos-localidades/normalizados/brasil.csv`
- Create: `data/catalogos-localidades/normalizados/chile.csv`
- Create: `data/catalogos-localidades/normalizados/uruguay.csv`
- Modify: `data/catalogos-localidades/README.md`

**Interface:** Common header `pais_codigo,fuente,codigo_provincia,provincia,codigo_localidad,localidad,contexto,categoria,latitud,longitud,version`. Use country-specific adapters for GeoRef CSV, IBGE GeoPackage, Chile GeoJSON, and Uruguay GeoPackage. Preserve original files and source codes; emit UTF-8 CSV.

- [ ] Add normalization checks for row counts, nonempty identities, duplicate identities, and coordinate ranges; cover Argentina 4,028, Brazil 87,362 unique locality codes (from 96,163 categorized rows), Chile 3,894, and Uruguay 652.
- [ ] Run the normalizer on the downloaded packages and record counts, source dates, and SHA-256 values in the README.

### Task 2: Add shared source identity and coordinates

**Files:**
- Create: `app/Database/Migrations/2026-10-30-130000_ExtendGeographicCatalogs.php`
- Create: `tests/unit/Database/Migrations/GeographicCatalogMigrationContractTest.php`
- Create: `tests/database/GeographicCatalogMigrationTest.php`

- [ ] Write the migration regression covering nullable metadata for existing rows and a unique global `identificador_fuente`.
- [ ] Add source identity to provinces/localities, administrative context/category, `DECIMAL` latitude/longitude, and an append-only import history with country/source/version/hash/counts/actor; keep foreign keys and rollback safe.
- [ ] Confirm existing locality IDs and trip foreign keys remain valid across `up()`; verify duplicate source identity rejection and rollback refusal after import history exists.

### Task 3: Implement application preview and confirm use cases

**Files:**
- Create: `app/Application/Geography/ImportLocalities.php`
- Create: `app/Application/Geography/PreviewLocalities.php`
- Create: DTO/value objects and `app/Application/Geography/Port/LocalityCatalogImportStore.php`
- Create: focused application tests.

- [ ] Validate supported country codes, UTF-8 text, required source identity/name, coordinate ranges, duplicate source codes, and preview summary counts.
- [ ] Confirm only a fully valid batch; surface row-level errors and preserve unchanged source records.
- [ ] Use the port only; no CodeIgniter/database imports in Application or Domain.

### Task 4: Persist the batch idempotently

**Files:**
- Create: `app/Infrastructure/Geography/CodeIgniterLocalityCatalogImportStore.php`
- Modify: `app/Config/Services.php`
- Create: MariaDB integration/feature tests for the adapter.

- [ ] Persist countries, administrative subdivisions, and localities in one transaction.
- [ ] Upsert by globally unique source identity; update source fields in place without changing locality IDs or deleting records.
- [ ] Roll back the full batch on database failure; verify duplicate and update counts.

### Task 5: Add globally authorized admin screen

**Files:**
- Create: `app/Controllers/GeographicCatalogImports.php`
- Create: `app/Views/geography/locality-import.php`
- Modify: `app/Config/Routes.php` and global-admin authorization/configuration as needed.
- Create: HTTP authorization and upload tests.

- [ ] Restrict preview and import actions to the global administrator; company users cannot mutate this catalog.
- [ ] Add file size/type validation, CSRF-protected preview and confirmation, and a human-readable result summary.
- [ ] Display source, version, country, adds, updates, unchanged rows, and row-level errors.

### Task 6: Load and verify staging catalog

**Files:**
- Modify: `data/catalogos-localidades/README.md` with staging load result.

- [ ] Before remote mutation, SSH-verify `fasa_189`, inspect Docker/Coolify app and DB labels/status/image, and identify the exact staging project and commit.
- [ ] Deploy the approved code to the identified staging stack and apply the migration without restarting or deleting persistent volumes.
- [ ] Import the four normalized files through the admin screen; report exact accepted rows and errors per country.
- [ ] Verify the trip locality search returns Ampere and Buenos Aires and that global catalog counts are independent of company.
- [ ] Confirm no trip, odometer reading, or historic import changed.

### Task 7: Document operations and sources

**Files:**
- Modify: `docs/IMPORTACION_CLIENTES.md` only if its inaccurate statement about initial geography data still applies.
- Modify: `data/catalogos-localidades/README.md`.
- Modify: relevant Viajes operation documentation.

- [ ] Document the common CSV contract, who may import, preview/confirm steps, source years, reimport behavior, and future source update procedure.
- [ ] Keep OSM distance estimation explicitly deferred.
