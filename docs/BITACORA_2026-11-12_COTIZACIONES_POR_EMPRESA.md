# Maestro de cotizaciones por empresa

**Fecha:** 12 de noviembre de 2026
**Contexto:** Viajes (bounded context 12). `viajes_monedas` sigue siendo el
catálogo compartido de solo lectura; la novedad es la cotización vigente por
empresa.
**Alcance:** merge de `origin/feature/viajes-cotizaciones`
(`be236904 feat(viajes): agregar maestro de cotizaciones por empresa`) a
`main` (`3a0e1114`), con resolución de conflictos contra moneda por flete,
conceptos con moneda y modal de localidades. Deploy quirúrgico a Ferozo.

---

## 1. Qué se encontró

- La cotización por empresa **existía pero nunca se había mergeado**: vivía
  solo en la rama `feature/viajes-cotizaciones`, por eso en producción
  (`Viajes > Maestros`, ver captura del usuario) no aparecía ninguna pestaña
  de cotizaciones.
- `main` había avanzado 64 commits desde la bifurcación (`5526c82b`), así que
  el merge trajo conflictos en 10 archivos fuente más bundles generados.

## 2. Qué se publicó

- **Migración `2026-11-04-130000_CreateCompanyTripExchangeRates`**: crea
  `viajes_cotizaciones` (`empresa_id`, `moneda CHAR(3)`,
  `cotizacion DECIMAL(18,6)`, única por empresa+moneda, auditoría
  created/updated_by). Las monedas siguen compartidas; cada empresa define su
  cotización a ARS. Los movimientos conservan su snapshot: cambiar una
  cotización nunca recalcula históricos.
- **Maestros:** `Viajes > Maestros > Cotizaciones` (`/viajes/maestros`,
  ancla `#cotizaciones`), `POST viajes/maestros/cotizaciones`, permiso
  `viajes.maestros.editar`. ARS siempre vale 1 y no se edita.
- **Precarga en viajes:** `CodeIgniterTripFormReadModel` y
  `CodeIgniterOperationalTripReadModel` devuelven `currencies[].rate`; el
  alta (`TripOperationsCreatePage`) y el detalle
  (`TripOperationsDetailPage`) autocompletan la cotización al elegir moneda,
  sin pisar lo que el operador ya escribió. Convivencia con moneda por flete:
  primero se aplica `useTripLegMoney` y solo si queda vacío se usa el rate.
- **Bundles regenerados** con `npm run build` (`BUILD_SYNC=OK`).

## 3. Verificación

- **PHPUnit:** 1797 pruebas, 7155 aserciones, 45 omitidas, 0 fallos.
- **Vitest:** 85 archivos, 475 pruebas, 0 fallos.
- Incluye `CodeIgniterTripExchangeRatesTest` (nuevo) y casos de
  `TripMasterCatalog` + `TripMastersPage` para cotizaciones, conservando los
  de conceptos con moneda.
- `php -l` sin errores en los 7 PHP tocados.

## 4. Publicación

- Deploy quirúrgico por FTPS (`scripts/deploy-ferozo.py --yes --skip-tests`,
  tests ya corridos aparte): **40 archivos** (14 de `app/` + migración + 26
  de `assets/dashboard/`).
- `MIGRATION_STATUS_HTTP=200` → `MIGRATIONS=OK`;
  `migrate.php` subido, ejecutado y **borrado** (`HTTP 404`).
- Smoke tests: `login` 200, `favicon.ico` 200, `.env` 403, `app/` 403,
  `vendor/` 403, `writable/` 403.
- Bundle anterior conservado en Ferozo; la limpieza se hace aparte con
  remote-prune.
- Estado: `.deploy/production.json` → `3a0e1114`.

## 5. Pendientes y notas

1. **Aceptación visual pendiente:** comprobar en producción como TSA que la
   pestaña `Cotizaciones` aparece en `Viajes > Maestros` y que al crear un
   viaje la cotización se precarga (ej. USD → valor guardado).
2. **Carga inicial:** cada empresa arranca sin cotizaciones (solo ARS=1);
   el primer guardado las crea vía `upsertBatch`.
3. El árbol local conserva archivos sin versionar del usuario (`.tmp/`,
   informes, `GUIA_CARGA_VIAJE_COMPLETO.md`): se preservaron con
   `git stash -u` durante el deploy y se restauraron después.
