# Deploy quirúrgico a producción: localidades principales

**Fecha:** 06/11/2026
**Commit desplegado:** `d9b66f1b`
**Base productiva previa:** `45d3db36`
**Destino:** Ferozo, FTPS — `https://vogelconsultoria.com.ar/mantenimiento/`

## 1. Alcance

Se desplegó únicamente el cambio del buscador de localidades de Viajes. No se
subió el `app/` completo, no se modificaron assets, `.env`, `vendor/` ni
`writable/`.

Archivos nuevos publicados:

- `app/Config/LocalityCatalog.php`
- `app/Domain/Geography/PrincipalLocalityPolicy.php`
- `app/Infrastructure/Geography/ProvinceLocalityRegistry.php`
- `app/Database/Migrations/2026-11-06-100000_AddPrincipalFlagToLocalities.php`

Archivos modificados:

- `app/Infrastructure/Geography/CodeIgniterLocalityCatalogImportStore.php`
- `app/Infrastructure/Trips/CodeIgniterTripLocalityLookup.php`
- `app/Config/Services.php`

## 2. Verificación previa

- Se verificó por FTPS que los tres archivos modificados coincidían
  exactamente con la base `45d3db36`.
- Se confirmó que los cuatro archivos nuevos no existían en producción.
- Se respaldaron los tres archivos reemplazados en
  `.deploy/localidades-principales/backup/` (local, fuera de Git).

## 3. Orden de publicación

1. Clases nuevas y migración.
2. Portón `migrate.php` y aplicación de la migración.
3. Consulta de estado posterior.
4. Borrado del portón.
5. Clases modificadas y `Config/Services.php` al final.

La primera ejecución aplicó la migración correctamente, pero el script se
detuvo porque el reporte de estado identifica la migración por versión
(`2026-11-06-100000`) y no por nombre de clase. La fase 2 se ejecutó aparte y
completó los tres archivos restantes. El portón quedó borrado.

## 4. Migración

- Estado previo: `2026-11-06-100000` no figuraba.
- Ejecución: `OK: migraciones aplicadas.`
- Estado posterior: `2026-11-06-100000` figura en el historial.
- `migrate.php` borrado por FTPS y verificado con HTTP 404.

La migración agrega `localidades.principal`, marca el catálogo principal,
crea una entrada por provincia en Argentina y conserva las demás localidades
para historial y equivalencias.

## 5. Smoke posterior

| Comprobación | Resultado |
|---|---|
| Hashes remotos de los 7 archivos | coinciden con `d9b66f1b` |
| `GET /login` | 200 |
| `GET /favicon.ico` | 200 |
| `GET /.env`, `/app/`, `/vendor/`, `/writable/` | 403 |
| `GET /migrate.php` | 404 |
| `GET /inicio` sin sesión | 200 con la página de login |
| `GET /viajes/operativos/localidades?q=san%20juan` sin sesión | 200 con la página de login |
| `GET /superadmin/geografia/localidades/importar` sin sesión | 200 con la página de login |

Las rutas protegidas devuelven la página de login con HTTP 200 (el shell Vue
resuelve la redirección), no un 302. No exponen datos de viajes.

## 6. Pendientes

- Validación autenticada del buscador `san juan`, `curitiba` y `são paulo` con
  una cuenta real de producción.
- Validación visual escritorio/móvil del autocompletado.
- Confirmar con el cliente que las 200 ciudades brasileñas de 150.000
  habitantes o más cubren sus destinos habituales.
