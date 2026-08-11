# Deploy en Ferozo

Este procedimiento despliega el sistema en la URL canonica:

```text
https://vogelconsultoria.com.ar/mantenimiento/
```

Ferozo no tiene SSH para este proyecto. El deploy se hace por FTPS y las
migraciones se ejecutan con un script PHP efimero protegido por token.

## Reglas de seguridad

- No versionar ni copiar al commit `.env`, `.ferozo-credentials*`,
  backups, dumps SQL, tokens, passwords ni archivos bajo `writable/`.
- No subir `frontend/node_modules/`.
- No subir `.git/`.
- No dejar `migrate.php` publicado al terminar.
- No publicar `vendor/` en el deploy normal. Si `composer.lock` cambia y el
  server necesita dependencias nuevas, actualizar `vendor/` como paso separado
  y verificado.
- Mantener la URL canonica en subdirectorio. El subdominio fue descartado.

## Preparacion local

1. Revisar el estado del checkout:

   ```powershell
   git status --short --branch
   ```

2. Ejecutar pruebas PHP con el runtime local disponible:

   ```powershell
   C:\xampp\php\php.exe -d extension=gd -d extension=zip vendor\bin\phpunit --no-coverage
   ```

3. Ejecutar pruebas y build del frontend:

   ```powershell
   cd frontend
   npm test -- --run
   npm run build
   cd ..
   ```

4. Confirmar que los bundles compilados existen en `assets/dashboard/` y que
   el manifest apunta a esos nombres.

## Preparar release

El release debe contener solo runtime y documentacion operativa segura:

- `app/`
- `assets/`
- `scripts/`
- `frontend/` sin `node_modules/`
- `docs/`
- `tests/`
- archivos raiz necesarios: `index.php`, `spark`, `.htaccess`,
  `composer.json`, `composer.lock`, `preload.php`, `phpunit.dist.xml`,
  `AGENTS.md`, `CHANGELOG.md`, `README.md`, `favicon.ico`, `robots.txt`,
  `design-qa.md`, `builds`, `.env.example`
- `.env` de produccion preparado localmente, sin versionarlo
- esqueleto de `writable/`: solo subcarpetas y archivos de placeholder

Para migraciones, copiar temporalmente:

```text
scripts/migrate-remote.php -> migrate.php
```

Ese archivo se sube solo para el deploy y se borra al terminar.

## Destino FTPS

Usar FTPS explicito con SSL/TLS en el canal de control. En la credencial
dedicada actual, la raiz `/` del FTP corresponde directamente a la carpeta
publica de la aplicacion `mantenimiento`.

Si se usa otra credencial, verificar primero con un listado FTPS: el destino
correcto debe mostrar en su raiz `index.php`, `app/`, `assets/`, `.env`,
`writable/` y `vendor/`.

Ejemplo de upload reanudable por carpetas:

```powershell
py -3 scripts\ferozo-ftps.py upload --credentials .ferozo-credentials --remote /app --local dist\ferozo-release\app
py -3 scripts\ferozo-ftps.py upload --credentials .ferozo-credentials --remote /assets --local dist\ferozo-release\assets
py -3 scripts\ferozo-ftps.py upload --credentials .ferozo-credentials --remote /scripts --local dist\ferozo-release\scripts
```

Luego subir archivos raiz puntuales, incluido `.env` y el `migrate.php`
efimero. No imprimir ni pegar credenciales en consola o documentacion.

## Migraciones remotas

El `.env` de produccion debe tener `MIGRATE_TOKEN` cargado.

1. Subir `scripts/migrate-remote.php` como `migrate.php` en la raiz web.

2. Consultar estado sin cambios:

   ```powershell
   curl.exe -sS --ssl-no-revoke -H "X-Migrate-Token: <TOKEN>" "https://vogelconsultoria.com.ar/mantenimiento/migrate.php?status=1"
   ```

   Debe terminar con:

   ```text
   OK: estado reportado. Sin cambios en la base.
   ```

3. Ejecutar migraciones pendientes:

   ```powershell
   curl.exe -sS --ssl-no-revoke -H "X-Migrate-Token: <TOKEN>" "https://vogelconsultoria.com.ar/mantenimiento/migrate.php"
   ```

   Debe terminar con:

   ```text
   OK: migraciones aplicadas.
   ```

4. Borrar inmediatamente `migrate.php` por FTPS.

5. Confirmar que ya no existe:

   ```text
   https://vogelconsultoria.com.ar/mantenimiento/migrate.php -> 404
   ```

## Verificacion posterior

Verificar por HTTP:

```text
/mantenimiento/          -> 302 o login
/mantenimiento/login     -> 200
/mantenimiento/favicon.ico -> 200
/mantenimiento/.env      -> 403
/mantenimiento/app/      -> 403
/mantenimiento/vendor/   -> 403
/mantenimiento/writable/ -> 403
/mantenimiento/migrate.php -> 404
```

Verificar assets:

```text
/mantenimiento/assets/dashboard/assets/<bundle>.js  -> 200
/mantenimiento/assets/dashboard/assets/<bundle>.css -> 200
```

Cuando se usa el Navegador integrado, abrir `/mantenimiento/login` y confirmar:

- titulo `Ingreso - Mantenimiento`
- formulario visible con email, password y CSRF
- bundles Vue/Tailwind cargados
- sin errores de consola

## Verificacion por hash

Para archivos criticos, descargar el remoto por FTPS y comparar SHA-256 contra
el archivo local. Como minimo:

- `.htaccess`
- `app/Config/Routes.php`
- `assets/dashboard/.vite/manifest.json`
- bundles JS/CSS activos

## Evidencia del deploy del 11 de agosto de 2026

- Frontend: `52` tests pasaron.
- PHPUnit: `223` tests, `586` assertions pasaron con PHP local cargando `gd`
  y `zip`.
- Migraciones remotas: `OK: migraciones aplicadas`.
- `migrate.php` fue eliminado despues de ejecutar.
- Navegador integrado: `/mantenimiento/login` cargo correctamente, con
  bundles `main-3Yzb83AS.js` y `main-DKp65hoD.css`, campos email/password,
  CSRF y sin errores de consola.
