# Deploy en Ferozo

Este procedimiento despliega el sistema en la URL canónica:

```text
https://vogelconsultoria.com.ar/mantenimiento/
```

Ferozo no tiene SSH para este proyecto. El deploy de producción se hace desde una máquina local por FTPS.

La estrategia estándar es:

```text
Git main
  ↓
script local Python
  ↓
diff contra último SHA desplegado
  ↓
upload incremental por FTPS
  ↓
migrate.php temporal
  ↓
migraciones ejecutadas dentro de Ferozo
  ↓
smoke tests + hashes
  ↓
registro del SHA desplegado
```

No se usa GitHub Actions ni Coolify para producción. Coolify puede seguir utilizándose para staging.

## Principios del deploy

- El deploy normal es **incremental**.
- El script local debe calcular los cambios con Git y subir solo lo necesario.
- El deploy completo se reserva para recuperación, reinstalación o resincronización.
- Las migraciones se ejecutan **dentro de Ferozo**, porque la base MySQL productiva no es accesible desde GitHub ni desde runners externos.
- El transporte FTPS reutiliza `scripts/ferozo-ftps.py`.
- Las migraciones reutilizan `scripts/migrate.php`, publicado temporalmente como `migrate.php`.
- Las credenciales FTPS permanecen solo en la máquina local y nunca se versionan.

## Reglas de seguridad

- No versionar ni copiar al commit `.env`, `.ferozo-credentials*`, backups, dumps SQL, tokens, passwords ni archivos bajo `writable/`.
- No subir `frontend/node_modules/`.
- No subir `.git/`.
- No dejar `migrate.php` publicado al terminar.
- No publicar `vendor/` en el deploy incremental normal.
- Si `composer.lock` cambia y producción necesita dependencias nuevas, actualizar `vendor/` como paso separado, explícito y verificado.
- Mantener la URL canónica en subdirectorio. El subdominio fue descartado.
- Antes de tocar producción debe poder ejecutarse un `--dry-run`.

## Herramientas existentes

El mecanismo de deploy ya cuenta con:

```text
scripts/ferozo-ftps.py
scripts/migrate.php
.ferozo-credentials
MIGRATE_TOKEN
```

El objetivo del orquestador de deploy es automatizar este procedimiento sin duplicar la lógica FTPS ni la lógica de migraciones.

## Script orquestador

El comando estándar debe ser:

```powershell
py -3 scripts\deploy-ferozo.py
```

Debe soportar al menos:

```powershell
py -3 scripts\deploy-ferozo.py --dry-run
py -3 scripts\deploy-ferozo.py
py -3 scripts\deploy-ferozo.py --full
```

### --dry-run

No modifica producción.

Debe:

1. verificar checkout y rama;
2. obtener el último SHA desplegado;
3. comparar ese SHA contra `HEAD`;
4. listar archivos agregados, modificados, renombrados y eliminados;
5. detectar migraciones nuevas;
6. indicar si cambió `composer.lock`;
7. indicar qué assets serán publicados;
8. mostrar el plan final del deploy.

### deploy incremental

Es el modo normal.

Debe:

1. verificar que la rama sea `main`;
2. verificar que el árbol de trabajo esté limpio;
3. hacer `git fetch origin`;
4. comprobar que el `HEAD` local corresponde al `main` que se desea publicar;
5. obtener el último SHA desplegado;
6. calcular el diff:
   ```text
   ultimo_sha..HEAD
   ```
7. ejecutar pruebas necesarias;
8. generar el build frontend cuando corresponda;
9. determinar exactamente qué archivos runtime deben subirse;
10. subirlos por FTPS mediante `scripts/ferozo-ftps.py`;
11. procesar eliminaciones remotas de forma explícita y segura;
12. publicar temporalmente `migrate.php`;
13. consultar el estado de migraciones;
14. pedir confirmación antes de aplicar migraciones productivas;
15. ejecutar las migraciones dentro de Ferozo;
16. eliminar inmediatamente `migrate.php`;
17. ejecutar smoke tests;
18. verificar hashes críticos;
19. registrar el nuevo SHA desplegado solo si todas las validaciones terminan correctamente.

### --full

Se reserva para:

- recuperación;
- reinstalación;
- resincronización completa;
- reparación de un remoto inconsistente.

No debe ser el mecanismo cotidiano.

## Prueba local segura

Para validar el orquestador sin tocar Ferozo:

1. Cambiar a la rama de prueba y actualizarla:

```powershell
git fetch origin
git switch feat/deploy-ferozo-orchestrator
git pull --ff-only
```

2. Ejecutar un dry-run comparando contra un commit anterior conocido:

```powershell
py -3 scripts\deploy-ferozo.py --dry-run --from-sha HEAD~1
```

Ese comando debe terminar con:

```text
DRY_RUN=OK
PRODUCTION_TOUCHED=NO
```

No requiere credenciales FTPS, no sube archivos, no publica `migrate.php` y no ejecuta migraciones.

3. Para probar un rango más real, reemplazar `HEAD~1` por el SHA de la versión actualmente desplegada:

```powershell
py -3 scripts\deploy-ferozo.py --dry-run --from-sha <SHA_PRODUCTIVO>
```

4. Revisar que la salida clasifique correctamente runtime, frontend, migraciones y eliminaciones.

La primera ejecución real deberá usar `--from-sha <SHA_PRODUCTIVO>` si todavía no existe `.deploy/production.json` local. Una vez que un deploy real termina completamente OK, el script guarda allí el nuevo SHA como referencia para el siguiente incremental.

### Limitaciones iniciales deliberadas

- `--full` está documentado pero todavía queda bloqueado en el orquestador hasta validarlo por separado.
- Las eliminaciones remotas detectadas hacen abortar el deploy real; no se borran automáticamente.
- La verificación remota SHA-256 todavía queda como paso a completar; esta primera versión imprime hashes locales críticos y mantiene los smoke tests HTTP.

## Preparación local

### Estado del checkout

```powershell
git status --short --branch
```

### Pruebas PHP

Con el runtime local disponible:

```powershell
C:\xampp\php\php.exe -d extension=gd -d extension=zip vendor\bin\phpunit --no-coverage
```

### Pruebas y build frontend

```powershell
cd frontend
npm test -- --run
npm run build
cd ..
```

Confirmar que los bundles compilados existen en `assets/dashboard/` y que el manifest apunta a esos nombres.

## Determinación de archivos

El deploy incremental debe partir del diff de Git, no de una lista armada manualmente.

Ejemplo conceptual:

```text
git diff --name-status <ultimo_sha_desplegado>..HEAD
```

El script debe clasificar al menos:

```text
A = agregado
M = modificado
D = eliminado
R = renombrado
```

Debe excluir del deploy normal todo lo que no corresponda a runtime productivo, por ejemplo:

- `.git/`
- `.github/`
- `frontend/node_modules/`
- backups
- dumps
- archivos temporales
- credenciales
- logs locales
- `writable/`
- documentación que no necesite publicarse

## Build y assets

Si cambia código frontend, el deploy debe ejecutar el build antes de calcular el conjunto final de assets a publicar.

El manifest de Vite debe quedar sincronizado con los bundles reales en:

```text
assets/dashboard/.vite/manifest.json
assets/dashboard/assets/
```

No deben eliminarse bundles antiguos a ciegas durante el upload. Cualquier limpieza remota debe ser explícita y posterior a la verificación del nuevo manifest.

## Destino FTPS

Usar FTPS explícito con SSL/TLS en el canal de control.

En la credencial dedicada actual, la raíz `/` del FTP corresponde directamente a la carpeta pública de la aplicación `mantenimiento`.

Si se usa otra credencial, verificar primero con un listado FTPS. El destino correcto debe mostrar en su raíz:

```text
index.php
app/
assets/
.env
writable/
vendor/
```

El transporte debe seguir reutilizando:

```powershell
py -3 scripts\ferozo-ftps.py upload --credentials .ferozo-credentials --remote /app --local <ruta_local>
```

y equivalentes para los demás archivos/carpetas.

No imprimir ni pegar credenciales en consola, logs o documentación.

## Migraciones remotas

El `.env` de producción debe tener `MIGRATE_TOKEN` cargado.

Las migraciones no se ejecutan desde GitHub ni desde la máquina local contra MySQL. Se ejecutan dentro de Ferozo mediante PHP.

### Publicar migrador temporal

Copiar temporalmente:

```text
scripts/migrate.php -> migrate.php
```

### Consultar estado

```powershell
curl.exe -sS --ssl-no-revoke -H "X-Migrate-Token: <TOKEN>" "https://vogelconsultoria.com.ar/mantenimiento/migrate.php?status=1"
```

Debe terminar con:

```text
OK: estado reportado. Sin cambios en la base.
```

o informar las migraciones pendientes.

### Aplicar migraciones

La aplicación de migraciones productivas debe requerir confirmación manual en el deploy estándar.

```powershell
curl.exe -sS --ssl-no-revoke -H "X-Migrate-Token: <TOKEN>" "https://vogelconsultoria.com.ar/mantenimiento/migrate.php"
```

Debe terminar con:

```text
OK: migraciones aplicadas.
```

### Retirar migrador

Borrar inmediatamente `migrate.php` por FTPS.

Confirmar:

```text
https://vogelconsultoria.com.ar/mantenimiento/migrate.php -> 404
```

Si `migrate.php` no puede eliminarse o sigue accesible, el deploy debe considerarse fallido.

## Verificación posterior

Verificar por HTTP:

```text
/mantenimiento/              -> 302 o login
/mantenimiento/login         -> 200
/mantenimiento/favicon.ico   -> 200
/mantenimiento/.env          -> 403
/mantenimiento/app/          -> 403
/mantenimiento/vendor/       -> 403
/mantenimiento/writable/     -> 403
/mantenimiento/migrate.php   -> 404
```

Verificar assets activos:

```text
/mantenimiento/assets/dashboard/assets/<bundle>.js  -> 200
/mantenimiento/assets/dashboard/assets/<bundle>.css -> 200
```

Cuando se use navegador, abrir `/mantenimiento/login` y confirmar:

- título `Ingreso - Mantenimiento`;
- formulario visible con email, password y CSRF;
- bundles Vue/Tailwind cargados;
- sin errores de consola.

## Verificación por hash

Para archivos críticos, descargar el remoto por FTPS y comparar SHA-256 contra el archivo local.

Como mínimo:

- `.htaccess`
- `app/Config/Routes.php`
- `assets/dashboard/.vite/manifest.json`
- bundles JS/CSS activos

Cuando un deploy solo modifica otros archivos concretos, también deben verificarse por hash los archivos principales de ese cambio.

## Registro del último SHA desplegado

El deploy necesita una referencia confiable para calcular el próximo incremental.

Debe conservar como mínimo:

```text
git_sha
deployed_at
status
```

El SHA solo debe actualizarse cuando:

```text
UPLOAD=OK
MIGRATIONS=OK o NONE
MIGRATE_PHP_REMOVED=OK
SMOKE_TESTS=OK
HASH_CHECK=OK
```

Si cualquiera de esos pasos falla, el SHA anterior sigue siendo la referencia de producción.

Puede conservarse una copia local, pero la fuente de verdad preferida debe poder recuperarse desde producción para no depender de una única computadora.

## Salida esperada del deploy

Ejemplo:

```text
==================================================
DEPLOY FEROZO - MANTENIMIENTO
==================================================

Producción actual : a278783
HEAD main         : f81398d

Cambios:
  PHP          5
  Assets       4
  Migraciones  2
  Eliminados   1

Tests PHP ........ OK
Tests frontend ... OK
Build ............ OK

UPLOAD=OK
FILES_UPLOADED=11
FILES_DELETED=1

MIGRATIONS_PENDING=2
MIGRATIONS=OK
MIGRATE_PHP_REMOVED=OK

LOGIN_HTTP=200
ASSETS_HTTP=200
SECURITY_PATHS=OK
HASH_CHECK=OK

DEPLOY_STATUS=OK
FROM=a278783
TO=f81398d
```

## Release completo

Para `--full`, el release puede contener runtime y documentación operativa segura:

- `app/`
- `assets/`
- `scripts/`
- `frontend/` sin `node_modules/`
- `docs/`
- `tests/`
- archivos raíz necesarios: `index.php`, `spark`, `.htaccess`, `composer.json`, `composer.lock`, `preload.php`, `phpunit.dist.xml`, `AGENTS.md`, `CHANGELOG.md`, `README.md`, `favicon.ico`, `robots.txt`, `design-qa.md`, `builds`, `.env.example`
- `.env` de producción preparado localmente, sin versionarlo
- esqueleto de `writable/`: solo subcarpetas y archivos placeholder

`vendor/` sigue siendo un paso separado salvo que el procedimiento completo indique explícitamente lo contrario.

## Criterio operativo

El flujo cotidiano esperado queda reducido a:

```text
merge a main
   ↓
py -3 scripts\deploy-ferozo.py --dry-run
   ↓
revisar cambios
   ↓
py -3 scripts\deploy-ferozo.py
   ↓
confirmar migraciones si existen
   ↓
DEPLOY_STATUS=OK
```

No hace falta GitHub Actions para este flujo y producción puede continuar alojada en Ferozo.

## Evidencia histórica

Las evidencias detalladas de deployments anteriores pueden conservarse en changelog, issues, PRs o documentación histórica. Este archivo debe mantenerse enfocado en el procedimiento vigente.
