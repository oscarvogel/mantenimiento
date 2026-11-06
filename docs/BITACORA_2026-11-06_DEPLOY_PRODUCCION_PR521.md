# Deploy quirúrgico a producción del PR #521 (Reportes y Automatizaciones)

**Fecha:** 06/11/2026
**PR:** #521 → mergeado a `main` en `b5529fab`
**Commit desplegado:** `1749f8bd`
**Base productiva previa:** `eedc249f`
**Destino:** Ferozo, FTPS — `https://vogelconsultoria.com.ar/mantenimiento/`

## 1. Alcance

Se desplegó únicamente el cambio del PR #521 sobre la base productiva `eedc249f`.
No se subió el `app/` completo de `main` ni los commits posteriores ajenos a este PR.

Archivos PHP publicados:

- 9 clases nuevas de Reportes/Automatizaciones y migraciones.
- 7 clases modificadas del ciclo, catálogo, reportes y shell.
- `Config/Services.php` y `Config/Routes.php` al final del cableado.
- 2 migraciones: `2026-11-05-120000_AddAutomationViewPermission` y
  `2026-11-05-130000_CreateAutomationExecutionStages`.

Assets:

- 15 bundles del build del commit.
- `assets/dashboard/.vite/manifest.json` publicado último.
- No se borraron bundles viejos; quedan como huérfanos no referenciados.

No se tocaron `.env`, `vendor/`, `writable/` ni archivos de raíz.

## 2. Verificación previa

- Auditoría FTPS archivo por archivo: todos los archivos modificados por el PR
  coincidían exactamente con la base `eedc249f`; los nuevos estaban ausentes.
- Respaldo de los archivos remotos que se iban a reemplazar en
  `.deploy/pr521-audit/backup/`.
- Migraciones de referencia probadas desde cero y up/down en MariaDB descartable
  de staging.

## 3. Orden de publicación

1. Clases nuevas.
2. Clases modificadas.
3. `Config/Services.php`.
4. `Config/Routes.php`.
5. Bundles.
6. Manifest.
7. Migraciones por el portón HTTP.
8. Borrado del portón y smoke HTTP.

## 4. Migraciones

Estado consultado por HTTP antes de aplicar: las migraciones nuevas no figuraban.
Después de aplicar y volver a consultar, ambas figuran en el historial.

`migrate.php` fue borrado por FTPS y verificado con HTTP 404.

## 5. Smoke posterior

| Comprobación | Resultado |
|---|---|
| `GET /login` | 200 |
| `GET /favicon.ico` | 200 |
| `GET /.env`, `/app/`, `/vendor/`, `/writable/` | 403 |
| `GET /migrate.php` | 404 |
| `GET /inicio`, `/reportes`, `/reportes/exportar`, `/automatizaciones` sin sesión | 302 a `/login` |
| Bundles referenciados por el manifest | 25 de 25 responden 200 |
| Hashes remotos de los archivos del PR | coinciden con `1749f8bd` |

## 6. Pendientes

- Validación autenticada de `/inicio`, `/reportes` y `/automatizaciones` con una
  cuenta real de producción; no había credenciales disponibles.
- Validación visual escritorio/móvil y navegación desde el portal.
- Smoke de canales externos: no se enviaron correos, WhatsApp ni push.
- El resto de los commits de `main` posteriores a `eedc249f` no se desplegaron en
  producción; este release es exclusivamente el PR #521.
