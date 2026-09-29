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

### Política: los bundles están versionados y CI los verifica

`app/Views/app.php` lee `assets/dashboard/.vite/manifest.json` desde el webroot
y emite a partir de ahí el `<script>` de entrada y sus hojas de estilo. Por eso
los bundles de `assets/dashboard/` **están versionados**: son parte del runtime
publicado, no un artefacto local descartable.

Esa decisión obliga a una regla única e innegociable:

> Si cambia el source frontend, el cambio incluye los bundles reconstruidos.
> Un PR que cambia `frontend/` y no toca `assets/dashboard/` **está incompleto**.

Esto ya se incumplió: entre los 25 commits que tocaron `frontend/`, 22 llegaron
a `main` sin regenerar los bundles, y CI no lo detectaba porque ejecutaba
`npm run build` y descartaba el resultado. El síntoma es una UI vieja publicada
con un source correcto.

### Verificación local

```bash
npm --prefix frontend run verify:build-sync
```

Reconstruye con la misma cadena que usa el release (`qa:encoding:source` →
`vite build` → `qa:encoding:build`) y compara contra el índice. Si el build
reproducible mueve un archivo de `assets/dashboard/`, falla y los lista.

El build es determinista: dos corridas seguidas sobre el mismo source producen
archivos idénticos byte a byte, así que la comparación es significativa.

### Verificación en CI

`.github/workflows/issue15-check.yml` corre `verify:build-sync` en cada push a
`main` y en cada PR a `main`. El resultado es el que se quiere:

| Situación | Resultado |
| --- | --- |
| Source modificado + bundles stale | **CI falla** |
| Source modificado + bundles commiteados | CI verde |
| `main` correctamente construido | CI verde |

El contrato de integridad del manifest y del consumo desde PHP se cubre además
con `frontend/tests/dashboardAssetsManifest.test.js`, dentro de la suite de
vitest.

### Limpieza de bundles huérfanos

Los nombres de archivo llevan hash de contenido, así que cada build deja atrás
los bundles del build anterior. Como el upload por FTPS no borra, el webroot
acumula historia. La limpieza se hace con `scripts/dashboard-assets.py`, que
toma el manifest vigente como única fuente de verdad:

```bash
# Clasificación sin tocar nada (local o remoto)
python scripts/dashboard-assets.py audit
python scripts/dashboard-assets.py remote-audit --credentials .ferozo-credentials

# Limpieza; sin --apply solo informa
python scripts/dashboard-assets.py prune --apply
python scripts/dashboard-assets.py remote-prune --credentials .ferozo-credentials \
    --backup-dir .deploy/backup-dashboard-assets --apply
```

Cada archivo se clasifica como `ACTIVE`, `ORPHAN_CONFIRMED` o `UNKNOWN`, y
**solo `ORPHAN_CONFIRMED` se elimina**. Se protegen siempre el manifest vigente,
sus `file`, `css`, `imports` y `dynamicImports` en cierre transitivo, y todo
archivo que el source versionado nombre. Si el manifest no se puede interpretar,
si falta la entrada `src/main.js` o si algún bundle declarado no está en el
destino, la herramienta aborta sin borrar nada: "no se sabe" se conserva.

`remote-prune` descarga cada archivo a `--backup-dir`, escribe su SHA-256 y solo
después borra; al final relee el webroot y confirma que el manifest y todo lo
protegido siguen en pie. La lógica tiene casos de prueba propios:

```bash
python scripts/dashboard-assets.py self-test
```

`--json <archivo>` vuelca el inventario clasificado completo para revisarlo
fuera de la terminal, que es lo que hay que adjuntar a una decisión de borrado:

```bash
python scripts/dashboard-assets.py remote-audit --json .deploy/audit/bundles.json
```

### Inventario en producción al 2026-09-29 (no aplicado)

Auditoría sobre el webroot real, contra el manifest vigente
`main-BshvSKBd` / `admin-BUNVn4QY` / `main-Bm3GJUWI` / `chatbot-CqAYF7Za` /
`chatbot-p1890OXW` / `reports-G_HMV49w` / `vendor-DQOjbuQd`:

| Estado | Archivos | Bytes |
| --- | --- | --- |
| `ACTIVE` | 7 | 1 005 019 |
| `ORPHAN_CONFIRMED` | 171 | 50 978 300 |
| `UNKNOWN` | 0 | — |

Los huérfanos se concentran en 135 versiones de `main-*` (49,1 MB), que son
builds completos de distintas fechas. Los 7 activos son exactamente el cierre
transitivo del manifest y coinciden con lo que sirve `app.php`.

**No se borró ninguno.** La decisión fue explícita: con el desafío anti-bot del
hosting activo no se podía completar el smoke HTTP posterior que el propio
issue exige, y sin ese smoke no hay verificación. Un residuo de 48,6 MB no
justifica esa concentración de riesgo. La herramienta queda lista y probada;
cuando se quiera recuperar el espacio, es un comando con respaldo y SHA-256
incluidos.

## Destino FTPS

Usar FTPS explícito con SSL/TLS en el canal de control.

En la credencial dedicada actual, la raíz `/` del FTP corresponde directamente a la carpeta pública de la aplicación `mantenimiento`.

> **La URL pública contiene `/mantenimiento/`, pero esa carpeta no existe en el
> servidor.** El hosting mapea `/mantenimiento/` de la URL a la raíz `/` del
> FTP. Un destino de upload `/mantenimiento/` crearía una subcarpeta fantasma y
> dejaría el sitio sin los archivos nuevos.
>
> Ya ocurrió: el deploy del 2026-09-18 publicó `app/Infrastructure/Expirations/`
> y la migración `2026-09-18-083000` dentro de `/mantenimiento/`, nunca en la
> raíz. Producción quedó con la versión anterior de esos archivos y, como los
> deploys son incrementales, nunca se corrigió: esos archivos no vuelven a
> aparecer en el diff con una base posterior. La carpeta `mantenimiento/` que
> quedó como residuo se retiró en el saneamiento de #432.
>
> **Regla: el destino de upload es la raíz `/`, sin carpetizar.** Ante la duda,
> listar la raíz y comprobar que `index.php` está ahí, no dentro de un
> subdirectorio.

El destino debe mostrar en su raíz:

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

### Desafío anti-bot del hosting

Desde 2026-09-29, `vogelconsultoria.com.ar` responde con una página intermedia
de openresty (`Server: openresty/1.31.1.1`, título "One moment, please..." o
"Un momento…", icono `data:,`, recarga automática a los 5 s) en lugar del sitio.
Aparece tanto en `/login` como en rutas de assets, así que **un `HTTP 200` con
ese cuerpo no es evidencia de nada**: el tamaño ronda los 12 000 bytes y el
`Content-Type` es `text/html` incluso para un `.js`.

Cómo trabajar alrededor:

- la respuesta real de `/login` ronda los 2 500 bytes; cualquier cuerpo de ~12 KB
  es el desafío;
- `curl` y `urllib` simples no lo resuelven, porque exige ejecutar JavaScript;
- un navegador real (**sí** se abre el sitio: con Chromium el título de
  `/login` es `Ingreso - Mantenimiento` y la página trae formulario y CSRF), pero
  conviene capturar el título de la pestaña y el `content-type` de cada ruta
  para no confundir un `HTTP 200` del desafío con un `HTTP 200` del sitio. Un
  bundle `.js` que responda `text/html` está interceptado, no servido.

Mientras el desafío esté activo, los códigos HTTP de un smoke automático no son
concluyentes: registrarlo así en el reporte en lugar de reportar un falso verde.

## Higiene del webroot

El home FTPS es el webroot real. Además de `app/`, `assets/` y los archivos de
raíz, la auditoría de #432 encontró en producción residuos que no pertenecen al
proyecto y que quedaban accesibles por HTTP:

| Residuo | Estado HTTP previo | Origen |
| --- | --- | --- |
| `reset-preventive-production.php` | 405 en GET | script one-shot de una intervención de agosto |
| `rebuild-service-motor-production.php` | 405 en GET | ídem |
| `probe_root.txt` | 200 legible | sonda de webroot |
| `mantenimiento/` | 500 en `Expirations.php` | deploy directed a la carpeta equivocada |
| `test.html`, `test_info.php` | 200 legible | otro proyecto de la misma cuenta |

Los scripts `reset-*` y `rebuild-*` exigen `POST` más un token cuyo hash SHA-256
está en el propio archivo, así que no son ejecutables sin el secreto, pero
quedan published y borran solos tras una corrida autorizada: si se loses el
token, el archivo se elimina. Aun así no tienen razón de estar en el webroot.

Procedimiento para retirar un residuo, sin excepciones:

1. confirmar que no está en el repo (`git ls-files`), que nada lo referencia y
   que no es necesario en runtime;
2. descargarlo por FTPS a un backup local y registrar su SHA-256;
3. verificar que el backup coincide con el tamaño remoto;
4. borrar, y releer el webroot para confirmar que ya no está;
5. hacer smoke HTTP y comprobar que no apareció ningún 500.

Sobre `test.html` y `test_info.php`: no pertenecen a este proyecto (referencian
`/registro_gatos/`) y son seguros de retirar, pero quedan fuera del alcance de
#432 y no se tocaron. Decisión del usuario: revisarlos por separado.

## Residuos retirados en #432

Ejecutado el 2026-09-29 por FTPS, con respaldo local previo y SHA-256 por
archivo, y verificado por relectura del webroot:

| Residuo | Bytes | SHA-256 (prefijo) |
| --- | --- | --- |
| `reset-preventive-production.php` | 4 944 | `4733399304a4c353` |
| `rebuild-service-motor-production.php` | 7 012 | `eca9a4a8f90124d8` |
| `probe_root.txt` | 12 | `04ffe72c1e7e25e7` |
| `mantenimiento/Expirations.php` | 25 473 | `1b0029ccd5218a02` |
| `mantenimiento/app/Database/Migrations/2026-09-18-083000_...php` | 1 957 | `1c66cf2c09add43d` |
| `mantenimiento/app/Infrastructure/Expirations/CodeIgniterExpirationActiveVersionManager.php` | 1 793 | `125adbdd4455eaf4` |
| `mantenimiento/app/Infrastructure/Expirations/CodeIgniterExpirationImportGateway.php` | 6 644 | `78c7171dea23aee6` |

Los tres últimos son copias de archivos que sí viven en el repo, publicadas por
el deploy dirigido a la carpeta equivocada. La carpeta `mantenimiento/` quedó
vacía y se eliminó.

Smoke posterior en navegador real: `/`, `/login`, `/dashboard` y `/superadmin`
responden con el login (`Ingreso - Mantenimiento`, formulario y CSRF presentes,
0 errores de consola); el bundle de entrada y el manifest devuelven 200 con su
`content-type` correcto; los cuatro residuos devuelven 404.

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
