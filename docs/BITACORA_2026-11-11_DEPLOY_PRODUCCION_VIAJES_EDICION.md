# Bitácora — Deploy quirúrgico a producción (2026-11-11)

## Alcance

Se desplegó en Ferozo el estado de `main` hasta `5d6233e0`, partiendo del SHA
productivo registrado `c1893641` (base `d9b66f1b` + autocompletado de provincia).

Incluye:

- Edición de viajes `PLANIFICADO`/`EN_CURSO` y edición de tramos.
- Fletes de facturación y liquidación opcionales mientras el viaje está abierto.
- Cierre bloqueado hasta que todos los tramos tengan ambos fletes.
- Diario de bordo (migración `2026-11-06-130000` y su código/UI).
- Fixes de perfiles por empresa de #485 (`SuperAdmin::index` y `?v=` del entry).

## Procedimiento

1. Backup quirúrgico previo por helper HTTP temporal:
   - Tablas: `migrations`, `viaje_operativo_tramos`, `viaje_operativo_otros_haberes`.
   - Archivo local: `dist/ferozo-backup-20261111-133153.sql`.
   - SHA-256: `5e62d2b7a6448629e91554e4c614b6c539400e314ff5e11e070150b5a8c998ec`.
   - Helper `backup.php` eliminado y verificado 404.
2. Deploy incremental con `scripts/deploy-ferozo.py --yes --skip-tests`:
   - 70 archivos publicados (3.254.550 bytes), sin subir `vendor/` ni `.env`.
   - Build Vue completo de `assets/dashboard` publicado.
   - Bundles anteriores conservados en el hosting; el manifest nuevo deja de
     referenciarlos.
3. Migraciones aplicadas por helper HTTP temporal:
   - `2026-11-06-130000_CreateOperationalTripLogbook`
   - `2026-11-11-090000_MakeOperationalTripLegAmountsOptional`
   - `migrate.php` eliminado y verificado 404.
4. Smoke tests: login 200; `favicon.ico` 200; `.env`, `.env.example`, `app/`,
   `vendor/` y `writable/` 403; `migrate.php` 404.
5. Estado productivo guardado en `.deploy/production.json` con `git_sha`
   `5d6233e0`.

## Pruebas previas

- PHPUnit: 1752 pruebas OK (45 skipped).
- Vitest: 463 pruebas OK.
- `npm run verify:build-sync`: OK.
- Staging `fasa_189` verificado con el viaje #3 de TSA: edición, bloqueo de
  cierre por fletes faltantes y habilitación al completarlos.

## Notas operativas

- No se reemplazó el `.env` productivo ni se rotaron credenciales.
- No se subió `vendor/`.
- El sitio quedó detrás del desafío anti-bot de Ferozo para navegadores/curl
  externos después del deploy; los smoke tests del orquestador pasaron con el
  User-Agent de deploy.
- El backup SQL queda en `dist/` (ignorado por Git) para rollback de las tablas
  afectadas.
