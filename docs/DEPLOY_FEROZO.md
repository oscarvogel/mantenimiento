# Despliegue en Ferozo (vía FTP)

Esta guia documenta el deploy del sistema de mantenimiento en el hosting
Ferozo de Vogel Consultoria. La URL canonica y unica es:

- `https://vogelconsultoria.com.ar/mantenimiento/`

La autodeteccion de `baseURL` que esta en `app/Config/App.php` toma la URL
del request, asi que el switch de una URL a la otra se hace unicamente
cambiando donde apunta el webroot, sin tocar la aplicacion.

---

## Importante: Ferozo no tiene acceso SSH

Este deploy asume que el hosting Ferozo no tiene shell, ni SSH, ni
composer en CLI, ni unzip en el server. Todo se hace por FTP puro
(FileZilla o similar).

Implicaciones directas:

- La estructura del proyecto es **plana**: todos los archivos (incluido
  `app/`, `vendor/`, `writable/`, `spark`, `composer.json`, etc.) viven
  adentro de `public_html/mantenimiento/`, no arriba de el.
- El `vendor/` viene **pre-instalado** adentro del zip. No se corre
  `composer install` en el server.
- No hay symlinks. Si se necesita, se hace una copia.
- Los permisos se ajustan desde el cliente FTP (FileZilla tiene la opcion
  "Permisos de archivo"), no con `chmod` por consola.
- Las tareas programadas se configuran desde el panel de Ferozo con un
  comando que apunte a un PHP del proyecto, o con un endpoint HTTP.
- La `encryption.key` se pregenera antes de armar el zip, no se corre
  `php spark key:generate` en el server.
---

## 1. Requisitos del hosting

- PHP 8.2 o superior (Ferozo tiene 8.4 FPM)
- MySQL o MariaDB
- Acceso FTP con SSL/TLS explicito (FTPS, puerto 21)
- Certificados TLS activos en los dos dominios
- Panel Ferozo con administracion de usuarios FTP y base de datos

Las restricciones de Ferozo conocidas (512 MB PHP, 60s ejecucion web,
128 MB POST, 100 GB disco) estan documentadas en la seccion 3.2 de la
especificacion funcional.

## 2. Estructura en el servidor

```
/home/<usuario>/
└── vogelconsultoria.com.ar/
    └── public_html/
        └── mantenimiento/                ← aca va TODO el contenido del zip
            ├── index.php                 ← front controller
            ├── .htaccess                 ← rewrite + HTTPS forzado
            ├── .env                      ← configuracion (NO versionado)
            ├── app/                      ← codigo de la aplicacion
            ├── vendor/                   ← dependencias PHP pre-instaladas
            ├── writable/                 ← logs, cache, sesion, uploads
            ├── tests/
            ├── docs/
            ├── composer.json
            ├── composer.lock
            ├── spark
            ├── phpunit.dist.xml
            ├── preload.php
            ├── favicon.ico
            ├── robots.txt
            ├── CHANGELOG.md
            └── README.md
```

No hay una carpeta `public/` separada. El `index.php` esta en la raiz de
`mantenimiento/` y carga `app/Config/Paths.php` directamente. Los paths
en `Paths.php` son relativos a `app/Config/`, asi que esto funciona.

---

## 3. Preparar el release localmente

El release debe contener solo el runtime: `app/`, `assets/`, `vendor/`, el
esqueleto protegido de `writable/`, los archivos raiz de CodeIgniter y el
`.env` de produccion. No incluir `frontend/`, `tests/`, `docs/`, credenciales,
capturas ni archivos fuente de imagen.

1. Ejecutar tests, construir Vue y preparar un directorio de staging con
   dependencias sin paquetes de desarrollo.

2. Construir el ZIP con rutas POSIX reproducibles:

   ```bash
   php -d extension=zip scripts/build-ferozo-archive.php dist/ferozo-release dist/ferozo-release.zip
   ```

3. Verificar el contenido, el hash SHA-256 y los requisitos de plataforma.

4. Para inventario, respaldo o carga FTPS reanudable se incluye:

   ```bash
   python scripts/ferozo-ftps.py inventory --credentials ferozo-credentials --remote /
   python scripts/ferozo-ftps.py backup --credentials ferozo-credentials --remote /app --local dist/backup/app
   python scripts/ferozo-ftps.py upload --credentials ferozo-credentials --remote /app --local dist/ferozo-release/app
   ```

5. **Editar el archivo `.env`** que esta adentro de la carpeta
   `mantenimiento/` antes de subir. Reemplazar los placeholders:

   - `database.default.password` con la password real de la BD
   - `email.SMTPHost` con el host SMTP real
   - `email.SMTPUser` y `email.SMTPPass` con las credenciales SMTP
   - `email.fromEmail` con el email que envia las alertas

   La `encryption.key` ya viene pregenerada. NO la cambies a menos que
   sepas lo que haces. Si la cambias, todas las sesiones y datos
   encriptados quedan invalidos.

   `uploads.privatePath` e `imports.privatePath` deben ser rutas absolutas
   fuera de `public_html/mantenimiento/`. Los adaptadores rechazan por diseno
   cualquier almacenamiento privado dentro del webroot plano.

---

## 4. Crear usuario FTP dedicado (recomendado)

En el panel de Ferozo, ir a **FTP / SSH access** y crear un usuario
adicional con las siguientes caracteristicas:

- **Home directory**: `/home/<usuario>/vogelconsultoria.com.ar/public_html/mantenimiento`
- **Permisos**: solo lectura (read) si solo se va a usar para
  verificar el deploy, o lectura + escritura si se va a actualizar
  desde ahi.
- **Protocolo**: FTP con SSL/TLS explicito (FTPS). Ferozo exige SSL en
  el canal de control, asi que cualquier intento sin SSL da error
  `550 SSL/TLS required on the control channel`.

Con un usuario asi, Mavis (o el programador que tome el proyecto) puede
verificar el estado del deploy sin riesgo de tocar el resto del
hosting. Las credenciales van en `.ferozo-credentials` (archivo local
ignorado por git) y se usan solo en la sesion actual.

---

## 5. Subir por FileZilla

> **IMPORTANTE: NO subir `vendor/`.** El hosting Ferozo ya tiene `vendor/`
> pre-instalado en `/home/<usuario>/vogelconsultoria.com.ar/public_html/mantenimiento/vendor/`
> y NO debe sobreescribirse en cada deploy. Si el `composer.lock` cambio
> (nuevas deps o version bump), se actualiza `vendor/` por separado, NO
> como parte del upload del release. En el upload normal, `vendor/` no se
> toca.
>
> **NO subir tampoco**:
> - `.git/` (nunca)
> - `frontend/node_modules/` (assets de desarrollo, se compilan a `assets/`)
> - `*.bak`, `*.tar.gz`, `*.zip` (backups locales, no deben terminar en el server)
> - `phpunit.xml` (es copia de `phpunit.dist.xml`, se crea en local para correr tests)
> - `.env.ferozo.bak.*` (backup local del `.env`, queda en la maquina de desarrollo)
> - `src.tar` u otros tarballs intermedios que dejes en el arbol local
>
> **QUE SUBIR** (sobrescribir siempre):
> - Raiz: `index.php`, `spark`, `.htaccess`, `composer.json`, `composer.lock`,
>   `preload.php`, `phpunit.dist.xml`, `AGENTS.md`, `CHANGELOG.md`, `README.md`,
>   `favicon.ico`, `robots.txt`, `design-qa.md`, `builds`
> - `app/` (codigo PHP, completo)
> - `scripts/` (incluye `migrate-remote.php`, completo)
> - `frontend/` (assets Vue, completo)
> - `assets/`, `docs/`, `tests/` (completos)
> - `writable/` (esqueleto: solo `cache/`, `logs/`, `session/`, `uploads/`,
>   `debugbar/` con sus `index.html` y `.gitkeep`; NO sobreescribir
>   contenido runtime del server si ya existe)
> - `.env` (el de produccion, con `MIGRATE_TOKEN` ya cargado)



1. Abrir FileZilla y conectarse con el usuario del paso 4 (o el principal
   si no creaste uno nuevo).

2. En el panel izquierdo (local), navegar adentro de la carpeta
   `mantenimiento/` extraida en el paso 3.

3. En el panel derecho (Ferozo), navegar a
   `/home/<usuario>/vogelconsultoria.com.ar/public_html/`. Si la carpeta
   `mantenimiento/` no existe todavia, crearla con click derecho ->
   Crear directorio.

4. Doble clic sobre `public_html/mantenimiento/` en el panel derecho
   para entrar.

5. En el panel izquierdo, seleccionar todo (Ctrl+A) y arrastrar al
   panel derecho.

   **Cuidado**: subi el **contenido** de la carpeta `mantenimiento/`,
   no la carpeta en si. Si la subis entera, te queda
   `public_html/mantenimiento/mantenimiento/index.php` y la app no
   funciona.

6. FileZilla empieza a subir. Son ~200 archivos. Dependiendo de la
   conexion, tarda entre 2 y 10 minutos. Dejar que termine.

---

## 6. Ajustar permisos de `writable/`

PHP necesita escribir en `writable/cache/`, `writable/logs/`,
`writable/session/` y `writable/uploads/`. El ZIP los sube con
permisos `755` que ya alcanzan para escritura via PHP. Aun asi, en
algunos hostings los permisos FTP no son los mismos que los del PHP
runtime, asi que conviene revisarlos desde FileZilla.

Pasos:

1. En FileZilla, navegar a `public_html/mantenimiento/writable/`.

2. Click derecho sobre la carpeta `writable/` -> **Permisos de archivo**.

3. Configurar:
   - Owner: lectura + escritura + ejecucion
   - Group: lectura + escritura + ejecucion
   - Others: lectura + ejecucion
   - Valor numerico: **755**
   - Marcar **Recursivo en subdirectorios**
   - Aceptar

4. Repetir para las subcarpetas (`cache`, `logs`, `session`, `uploads`,
   `debugbar`) si el recursive no las tomo.

5. Verificar que los **archivos** dentro de `writable/` (como
   `index.html`, `.htaccess`) tienen permiso `644` y no `755`. Si el
   recursive cambio todo a `755`, volver a poner los archivos en `644`.

---

## 6.5. Correr migraciones despues del upload

Ferozo no expone SSH ni CLI, asi que php spark migrate no se puede correr en
el server. La alternativa soportada es scripts/migrate-remote.php, un script
PHP plano pensado para subirse UNA VEZ por deploy y borrarse al terminar.

### Preparacion (una sola vez por release)

1. Definir MIGRATE_TOKEN en el .env de produccion. Generar el valor con:
   `
   php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
   `
   El script exige que el token tenga al menos 32 caracteres.

2. Subir scripts/migrate-remote.php a la RAIZ del release como
   migrate.php por FTPS (mismo nivel que index.php y spark).
   NO versionar credenciales ni dejar el script permanente.

### Ejecucion por HTTP

Para ver el estado sin tocar la base:
`
curl -sS -H 'X-Migrate-Token: <TOKEN>' \
  'https://vogelconsultoria.com.ar/mantenimiento/migrate.php?status=1'
`

Para correr las migraciones pendientes:
`
curl -sS -H 'X-Migrate-Token: <TOKEN>' \
  'https://vogelconsultoria.com.ar/mantenimiento/migrate.php'
`

El script responde con texto plano: la lista de migraciones aplicadas, los
mensajes del runner y un OK/ERROR final. Cualquier error de base se ve ahi
antes que en el log de la app.

### Limpieza (obligatoria)

Borrar migrate.php por FTPS inmediatamente despues de cada deploy. El
script imprime el recordatorio al final, pero la limpieza la hace el operador.

### Por que un script y no un controller

- Es un helper de deploy, no parte de la aplicacion; no queremos que viva en
  pp/Controllers/ con un filtro permanente.
- Al ser standalone, se sube por FTPS en el mismo paso que el resto del
  release, sin tocar el codigo versionado.
- Al borrarse despues, la superficie de ataque publica no aumenta entre
  deploys.

## 7. Probar

Abrir en el navegador:

- `https://vogelconsultoria.com.ar/mantenimiento/`
  Debe redirigir o presentar el login Vue del sistema.

- `https://vogelconsultoria.com.ar/mantenimiento/login`
  Debe responder 200, cargar el WebP de fondo y los bundles Vue/Tailwind.

- `https://vogelconsultoria.com.ar/mantenimiento/favicon.ico`
  El icono del framework. Si da 404, el `.htaccess` no se subio.

- `https://vogelconsultoria.com.ar/mantenimiento/.env`
  **Debe dar 403 Forbidden** (no debe mostrar el contenido). Si muestra
  el archivo, la regla de bloqueo no esta activa.

Ademas, autenticar una cuenta valida y comprobar dashboard y reportes. Tests
locales y migraciones exitosas no sustituyen esta verificacion HTTP remota.

---

## 8. El `.htaccess` minimalista

El `.htaccess` que viene en el zip es la version **minimalista** que
Ferozo acepta. Es importante entender que Ferozo **NO banca** ciertas
directivas comunes de Apache que funcionan en otros hostings:

| Directiva | Estado en Ferozo | Consecuencia |
|---|---|---|
| `Options +FollowSymlinks` | NO permitida | Tira 500 Internal Server Error |
| `Options -Indexes` | NO permitida en este hosting | Tira 500 |
| `Options +SymLinksIfOwnerMatch` | NO probada, evitar | Probable 500 |
| `RedirectMatch` con regex compleja | Inestable | A veces 500 |
| `ServerSignature Off` | OK pero innecesaria | Quitada por simplicidad |
| `FilesMatch` con regex | OK pero limitado | Reemplazado por `<Files>` |
| `RewriteEngine On` | OK | - |
| `RewriteCond` / `RewriteRule` | OK | - |
| `<Files>` simple | OK | - |

**Regla de oro para Ferozo**: si no sabes si una directiva anda, no
la pongas. Probala primero. Si tira 500, el cliente FTP te deja renombrar
el `.htaccess` a `.htaccess.bak` y ver si la app carga sin el.

El `.htaccess` que viene en el zip es este:

```apache
RewriteEngine On

# Forzar HTTPS
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

# Redirigir trailing slashes (excepto la raiz)
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} (.+)/$
RewriteRule ^ %1 [L,R=301]

# Reescribir a index.php si la URL no es un archivo ni un directorio real
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]

# Bloquear acceso directo al .env
<Files ".env">
    Order allow,deny
    Deny from all
</Files>
```

Los directorios privados (`app/`, `writable/`, `tests/`, `vendor/`) tienen
su propio `.htaccess` adentro con `Require all denied` o
`Deny from all`. Esos se commitean al repo para `app/`, `tests/` y
`writable/`. El de `vendor/` viene adentro del zip porque `vendor/` no se
commitea (regenerable con composer).

---

## 9. Troubleshooting

### HTTP 500 inmediato en todas las paginas

Causa mas probable: el `.htaccess` tiene directivas que Ferozo no
permite (ver seccion 8). Diagnostico rapido:

1. Desde FileZilla, renombrar `.htaccess` a `.htaccess.bak`.
2. Recargar el sitio.
3. **Si el 500 desaparece y ves HTML crudo sin CSS**: el problema es
   el `.htaccess`. Restaurar el del zip (que es el minimalista que
   Ferozo acepta).
4. **Si el 500 sigue**: el problema no es el .htaccess. Ver el resto
   de esta seccion.

### HTTP 500 con `intl: NO` en phpinfo

Causa: Ferozo no tiene habilitada la extension `intl` de PHP. CI4 la
requiere. Solucion: pedirle al soporte de Ferozo que la habilite. Como
alternativa temporal, se puede editar `composer.json` para remover el
requerimiento y usar `--ignore-platform-req=ext-intl` al instalar
composer, pero eso es un parche, no la solucion.

### HTML se ve pero sin CSS

Causa: el rewrite no funciona, o el `.htaccess` no se subio, o los
permisos de los archivos de assets estan mal. Verificar:

1. Que `.htaccess` este fisicamente en `mantenimiento/` (click en
   Servidor -> Forzar mostrar archivos ocultos en FileZilla).
2. Que `favicon.ico` se ve en `https://.../mantenimiento/favicon.ico`
   (si da 404, es problema de rewrite).
3. Que los archivos CSS/JS cargan con HTTP 200 (click derecho -> Ver en
   el navegador en las DevTools del browser).

### favicon.ico da 404 pero el resto anda

Causa: el rewrite esta capturando el favicon. Verificar que el
`.htaccess` incluye la regla de reescritura SOLO cuando el archivo no
existe (`RewriteCond %{REQUEST_FILENAME} !-f`).

### `.env` es accesible por URL

Causa: la regla `<Files ".env">` no se interpreto. Verificar que
existe en el `.htaccess`. Si la regla esta y aun asi se ve el archivo,
probar agregar `<FilesMatch "^\.env">` con regex.

### Permisos `writable/` mal

Causa: PHP no puede escribir y la app tira 500 al primer log. Verificar
desde FileZilla que `writable/` y sus subcarpetas tienen permiso `755`
y los archivos `644`. Ver seccion 6.

### El usuario FTP no puede acceder a `mantenimiento/`

Causa: el usuario FTP tiene un home distinto. Verificar en el panel
de Ferozo que el home del usuario es
`/home/<usuario>/vogelconsultoria.com.ar/public_html/mantenimiento`.
Si esta bien, contactarse con el soporte de Ferozo porque hay un
problema de chroot.

### El sitio carga pero con texto en ingles

Causa: el `app.defaultLocale` no esta configurado. Verificar que
`app/Config/App.php` tiene `public string $defaultLocale = 'es';` y
que `app/Config/App.php` se subio (Ferozo a veces filtra archivos
raros).

---

## 10. Subdominio descartado

Esta guia incluia antes una seccion de **Migracion futura a subdominio**
que proponia mover la app a `https://mantenimiento.vogelconsultoria.com.ar/`.
Esa opcion fue **descartada** el 5 de agosto de 2026.

Resumen del motivo:

- Ferozo emite el cert Let's Encrypt para un subdominio solo cuando se
  configura el vhost en el panel. Mientras tanto, sirve el cert wildcard
  generico `CN=*.ferozo.com` (Sectigo), que los navegadores modernos
  rechazan con `NET::ERR_CERT_COMMON_NAME_INVALID` para cualquier
  hostname que no sea `*.ferozo.com`.
- La autodeteccion de `baseURL` en `app/Config/App.php` ya funciona bien
  con el subdirectorio (`SCRIPT_NAME = /mantenimiento/index.php`) y la
  URL actual es estable.
- Mantener dos URLs activas (subdirectorio + subdominio) sin redirect 301
  desde el subdominio al subdirectorio duplica superficie de ataque y de
  mantenimiento sin beneficio claro para el cliente.

URL canonica: `https://vogelconsultoria.com.ar/mantenimiento/`.

Detalle completo, acciones de cleanup y leccion aprendida en
`docs/SUBDOMINIO_DESCARTADO.md`. Si en algun momento se quiere reactivar
el subdominio, leer ese documento antes de tocar nada.

---
---

## 11. Rotacion de credenciales

Si las credenciales del FTP quedan expuestas (por ejemplo, en este
chat o en un archivo commiteado por error), rotar lo antes posible:

1. En el panel de Ferozo, ir a **FTP / SSH access**.
2. Cambiar la password del usuario o crear uno nuevo.
3. Actualizar `.ferozo-credentials` local con la nueva password.
4. Quitar el archivo de credenciales del chat o historial donde haya
   quedado expuesto.

La `encryption.key` que esta en `.env` tambien se puede regenerar, pero
eso invalida cualquier dato que se haya encriptado con ella (sesiones,
archivos encriptados, etc.). En la primera version no se usa para
encriptar datos sensibles, asi que es seguro regenerarla.

---

## 12. Verificacion del deploy via FTP (con `.ferozo-credentials`)

Mavis puede conectarse al FTP para verificar el estado del deploy
usando las credenciales del archivo local `.ferozo-credentials`. Ejemplo
de uso:

```bash
# Listar el contenido del directorio mantenimiento
curl.exe -s --ssl-reqd -u "USUARIO:PASSWORD" -X LIST "ftp://HOST/"

# Descargar un archivo para inspeccionarlo
curl.exe -s --ssl-reqd -u "USUARIO:PASSWORD" "ftp://HOST/.htaccess" -o .htaccess.tmp

# Subir un archivo (ejemplo: el .htaccess nuevo)
curl.exe -s --ssl-reqd -u "USUARIO:PASSWORD" -T .htaccess "ftp://HOST/.htaccess"
```

Ferozo exige `--ssl-reqd` (SSL/TLS explicito en el canal de control).
Sin eso, da error `550 SSL/TLS required on the control channel`.

El archivo `.ferozo-credentials` esta en `.gitignore` con la regla
`/.ferozo-credentials*` (con wildcard por si hay backups). NO se commitea
nunca. Las credenciales se leen solo en la sesion actual y no se
guardan en la memoria persistente de Mavis.

---

## 13. Versionado

- **v0 (5 de agosto de 2026)**: deploy inicial.
  - CodeIgniter 4.7.4 con estructura plana (todo en
    `public_html/mantenimiento/`).
  - `.htaccess` minimalista compatible con Ferozo.
  - `.env` con `encryption.key` pregenerada.
  - `vendor/` pre-instalado (3.9 MB).
  - Zip listo en `dist/mantenimiento-ftp-v0.zip`.
  - `.ferozo-credentials` para que Mavis verifique el deploy.

## 14. Cambios respecto a versiones anteriores

- Antes se asumia SSH y se usaban symlinks. Ferozo no tiene SSH, asi que
  se migro a estructura plana con copia directa.
- Antes el `.htaccess` tenia directivas `Options` que Ferozo no banca.
  Se reescribio a una version minimalista compatible.

---

Si el programador o Mavis encuentran un problema que no esta
documentado aca, agregar una entrada a esta guia. El objetivo es que
el deploy sea repetible por cualquiera sin tener que pedir ayuda.

## 16. Despliegue verificado del 9 de agosto de 2026

- PHP remoto `8.4.23` y extensiones requeridas confirmadas.
- Release Vue/Tailwind y CodeIgniter aplicado por FTPS sobre la URL canonica.
- Base actualizada de 8 a 38 migraciones y de 9 a 30 tablas mediante un
  runner efimero autenticado, eliminado inmediatamente despues de ejecutarse.
- `InitialSeeder` ejecutado de forma idempotente.
- Login real, dashboard y reportes respondieron HTTP 200.
- `.env`, `app/`, `vendor/` y `writable/` respondieron HTTP 403.
- Login validado con Edge en `1906x943` y `390x844`, sin errores de consola,
  requests fallidos ni overflow horizontal.
- El backup SQL previo fue descargado y verificado localmente antes de retirar
  su copia remota. Los respaldos locales viven bajo `dist/` y no se versionan.

---

## 15. Nota: `app.baseURL` en Ferozo

En Ferozo, la autodeteccion del `baseURL` desde `SCRIPT_NAME` no funciona
bien (devuelve la raiz del dominio en vez del subdirectorio). El sintoma
es que despues del login el redirect va a `https://dominio.com/login/...`
sin el `/mantenimiento/`, y aparece "Not Found".

**Fix**: poner la URL fija en el `.env`:

```
app.baseURL = 'https://vogelconsultoria.com.ar/mantenimiento/'
```

Tambien dejar `app.indexPage = ''` (vacio) para que el rewrite de Apache
funcione sin `index.php` en la URL.

Nota historica: este apartado mencionaba antes la posibilidad de migrar a
`mantenimiento.vogelconsultoria.com.ar/`. Esa opcion fue descartada; ver
`docs/SUBDOMINIO_DESCARTADO.md` para el detalle.
