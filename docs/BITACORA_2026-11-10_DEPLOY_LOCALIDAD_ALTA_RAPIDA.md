# Deploy quirúrgico a producción: alta rápida de localidades

**Fecha:** 10/11/2026
**Feature en `main`:** `1ca39439`
**Release desplegado:** `34afbb1f`
**Tag del release:** `deploy/localidad-alta-rapida-20261110`
**Base productiva previa:** `d9b66f1b`
**Destino:** Ferozo, FTPS — `https://vogelconsultoria.com.ar/mantenimiento/`

## 1. Alcance

Se aplicó únicamente la feature de alta rápida de localidades desde el buscador de
tramos de Viajes. El release se armó desde `d9b66f1b`, que era el estado real de
producción, y **no** incluye los commits posteriores de `main` (diario de bordo,
ajuste de cards de carga ni documentación).

Archivos PHP nuevos:

- `app/Application/Trips/CreateTripLocality.php`
- `app/Application/Trips/ListTripLocalityProvinces.php`
- `app/Application/Trips/Port/TripLocalityCreator.php`
- `app/Domain/Geography/InvalidLocalityInput.php`
- `app/Domain/Geography/LocalityName.php`
- `app/Infrastructure/Trips/CodeIgniterTripLocalityCreator.php`

Archivos PHP modificados:

- `app/Application/Trips/GetOperationalTrip.php`
- `app/Application/Trips/GetTripFormOptions.php`
- `app/Application/Trips/Port/TripLocalityLookup.php`
- `app/Config/Routes.php`
- `app/Config/Services.php`
- `app/Controllers/OperationalTrips.php`
- `app/Infrastructure/Trips/CodeIgniterTripLocalityLookup.php`

Assets:

- 9 bundles nuevos del build del release.
- `assets/dashboard/.vite/manifest.json` publicado al final.
- Los bundles anteriores se conservan en el servidor como huérfanos no
  referenciados.

No se tocaron `.env`, `vendor/`, `writable/`, `frontend/`, `tests/` ni archivos
raíz. No hubo migraciones.

## 2. Verificación previa

- Los 8 archivos remotos que se iban a reemplazar coincidían exactamente con
  `d9b66f1b`.
- Los 6 archivos nuevos no existían en producción.
- Los archivos de diario de bordo seguían ausentes en producción.
- Backup de los archivos reemplazados en
  `.deploy/locality-quick-create/backup/` (local, fuera de Git).

## 3. Orden de publicación

1. Clases nuevas.
2. Clases modificadas.
3. 9 bundles nuevos.
4. Manifest.
5. Verificación remota por hash.
6. Smoke HTTP.

## 4. Verificación posterior

- Hash SHA-256 remoto de los 13 archivos PHP y los 9 bundles: coincide con el
  release `34afbb1f`.
- Hash del manifest remoto: coincide con el release.
- Los archivos de diario de bordo continúan ausentes.
- `GET /login` → 200.
- `GET /favicon.ico` → 200.
- `GET /.env`, `/app/`, `/vendor/`, `/writable/` → 403.
- `GET /migrate.php` → 404.
- `GET /viajes/operativos/localidades?q=capitan` sin sesión → 200 (login).
- `GET /viajes/operativos/localidades/provincias` sin sesión → 200 (login).
- `POST /viajes/operativos/localidades` sin sesión → 200 (login), sin error 500.
- Los 25 assets referenciados por el manifest responden 200.

## 5. Pendientes

- Validación autenticada en producción: abrir un tramo, buscar una localidad
  inexistente, darla de alta con nombre y provincia, y confirmar que queda
  seleccionada sin perder el formulario.
- Validación visual escritorio/móvil del buscador y del modal.
- Desplegar en otro release los commits de `main` posteriores a `d9b66f1b`
  (diario de bordo y ajuste de cards), que no forman parte de este cambio.

## 6. Actualización: provincia en autocompletado

**Fecha:** 10/11/2026
**Fix en `main`:** `20db1486`
**Release desplegado:** `c1893641`
**Tag del release:** `deploy/localidad-autocomplete-provincia-20261110`

Se reemplazó el `<select>` de provincia del modal por el mismo componente de
autocompletado que usa el sistema. El modal conserva nombre y provincia como
datos básicos; el país sigue derivándose de la provincia.

Este release se armó desde `34afbb1f` (producción + feature anterior) y solo
publicó assets:

- 4 bundles JS nuevos.
- `assets/dashboard/.vite/manifest.json` al final.

No se volvieron a subir archivos PHP porque no cambiaron. Backup del manifest
anterior en `.deploy/locality-quick-create/backup-v2/`.

Verificación: hashes remotos de los 4 bundles y del manifest coinciden con el
release; los 30 assets referenciados por el manifest responden 200; `/login`
200, `.env`/`app/`/`vendor/`/`writable/` 403, `migrate.php` 404 y el endpoint
de provincias responde sin error 500.

Pendiente: validación autenticada en producción del autocompletado de provincia
y de la selección final de la localidad creada.
