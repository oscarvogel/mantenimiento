# Despliegue en Ferozo

Esta guia describe el despliegue del sistema de mantenimiento en el hosting
Ferozo de Vogel Consultoria. La misma instalacion sirve a dos URLs:

- **Subdirectorio** (etapa inicial): `https://vogelconsultoria.com.ar/mantenimiento/`
- **Subdominio dedicado** (migracion posterior): `https://mantenimiento.vogelconsultoria.com.ar/`

El switch se hace unicamente creando o quitando el symlink correspondiente; la
aplicacion detecta automaticamente cual es la URL actual y no requiere
cambios de configuracion.

---

## 1. Requisitos del servidor

- PHP 8.4 FPM con extensiones: `intl`, `mbstring`, `mysqlnd`, `curl`, `xml`, `zip`, `gd`
- MySQL o MariaDB
- Acceso SSH al usuario de hosting
- Panel Ferozo con tareas programadas (para el proceso diario de alertas)
- Certificados TLS activos (Let's Encrypt o equivalente) en los dos dominios

Las restricciones conocidas de Ferozo (512 MB PHP, 60s ejecucion, 128 MB POST)
estan documentadas en la seccion 3.2 de la especificacion funcional.

## 2. Estructura en el servidor

```
/home/<usuario>/
├── mantenimiento-app/                  ← checkout del repo (NO publico)
│   ├── app/  writable/  vendor/
│   ├── public/                         ← fuente de los symlinks
│   ├── .env                            ← NO versionado
│   ├── spark
│   ├── composer.json
│   └── ...
│
├── vogelconsultoria.com.ar/public_html/
│   └── mantenimiento/                  ← symlink a mantenimiento-app/public
│
└── mantenimiento.vogelconsultoria.com.ar/
    └── public_html/                    ← symlink a mantenimiento-app/public
```

`<usuario>` es el usuario FTP/SSH del hosting.

## 3. Subir el codigo

```bash
# En tu maquina local
cd O:\mantenimiento
git checkout main
git pull
# Si subes el vendor, no hace falta composer install en el server.
# Si no, en el server:
cd /home/<usuario>/mantenimiento-app
git clone https://github.com/oscarvogel/mantenimiento.git .
composer install --no-dev --optimize-autoloader
```

> **Nota sobre `vendor/`:** pesa ~4 MB. Por ahora lo dejamos commiteado para
> que el primer deploy sea `git pull` y listo. Si en algun momento crece
> demasiado, se quita del repo y se agrega `composer install` al script de
> deploy.

## 4. Crear los symlinks

```bash
# Subdirectorio en vogelconsultoria.com.ar
ln -s /home/<usuario>/mantenimiento-app/public/* /home/<usuario>/vogelconsultoria.com.ar/public_html/mantenimiento/

# Subdominio dedicado
mkdir -p /home/<usuario>/mantenimiento.vogelconsultoria.com.ar/public_html
ln -s /home/<usuario>/mantenimiento-app/public/* /home/<usuario>/mantenimiento.vogelconsultoria.com.ar/public_html/
```

> **Verificacion post-symlink:** `ls -la public_html/mantenimiento/` debe
> mostrar los archivos de `mantenimiento-app/public/`. Si Ferozo no sigue
> symlinks, en lugar del symlink copiar con `rsync` (ver seccion 9).

## 5. Configurar el .env

```bash
cd /home/<usuario>/mantenimiento-app
cp .env.example .env
nano .env   # o vim
```

Editar como minimo:

- `CI_ENVIRONMENT = production`
- `app.forceGlobalSecureRequests = true`
- `database.default.*` con las credenciales reales
- `email.*` con las credenciales SMTP reales
- `alerts.*` con la hora y reglas del cliente
- `uploads.privatePath` apuntando a una ruta fuera de `public_html/`
- `encryption.key` generada con `php spark key:generate`

```bash
# Generar la clave de encripcion
php spark key:generate

# Permisos correctos de writable
chmod -R 755 writable
chmod 644 .env
```

## 6. Crear la base de datos y correr migraciones

Desde el panel de Ferozo (o phpMyAdmin):

1. Crear la base de datos `mantenimiento` con cotejamiento `utf8mb4_unicode_ci`.
2. Crear el usuario `mantenimiento` con password segura y permisos completos
   sobre esa base.

Despues, desde SSH:

```bash
cd /home/<usuario>/mantenimiento-app
php spark migrate
php spark db:seed InitialSeeder
```

> Todavia no existen las migraciones del proyecto (estan en la etapa 1 de
> la spec). El comando `migrate` dejara la base vacia hasta que se cree la
> primera migracion. Los seeders tampoco existen todavia.

## 7. Tarea programada de Ferozo

En el panel de Ferozo, ir a **Tareas programadas** y crear una que se ejecute
diariamente a las 07:00:

```text
Comando:   php /home/<usuario>/mantenimiento-app/spark alerts:daily
Horario:   07:00
```

> El comando `alerts:daily` se crea en la etapa 5 de la spec. Mientras tanto,
> la tarea puede dejarse apuntando a un comando vacio o desactivada.


## 8. Verificacion post-deploy

```bash
# 1. Smoke test por HTTP
curl -I https://vogelconsultoria.com.ar/mantenimiento/
curl -I https://mantenimiento.vogelconsultoria.com.ar/

# Ambos deben devolver HTTP 200 (o 302 redirigiendo a login) sin errores 500.

# 2. Smoke test por CLI
cd /home/<usuario>/mantenimiento-app
php spark about

# 3. Verificar logs
tail -f writable/logs/log-*.log
```

Si la pantalla de bienvenida de CodeIgniter 4 se ve correctamente, el deploy
esta funcionando. Si tira 500, revisar el log de Ferozo (panel) o
`writable/logs/log-*.log`.


## 9. Plan B: rsync en lugar de symlinks

Si Ferozo no sigue symlinks con Apache (raro pero posible), reemplazar los
symlinks por un paso de rsync en cada deploy. Crear `scripts/deploy_ferozo.sh`
en el repo:

```bash
#!/usr/bin/env bash
set -euo pipefail

REMOTE="<usuario>@<host>"
APP_DIR="/home/<usuario>/mantenimiento-app"
WEBROOT_SUB="${REMOTE}:/home/<usuario>/vogelconsultoria.com.ar/public_html/mantenimiento/"
WEBROOT_DOM="${REMOTE}:/home/<usuario>/mantenimiento.vogelconsultoria.com.ar/public_html/"

# Sincroniza el contenido de public/ con el webroot.
# Los assets viejos se mantienen en el destino; los CSS y JS nuevos los
# referencia el index.html con sus hashes, asi que no hace falta limpiar.
rsync -av --exclude='.gitkeep' "${APP_DIR}/public/" "${WEBROOT_SUB}"
rsync -av --exclude='.gitkeep' "${APP_DIR}/public/" "${WEBROOT_DOM}"
```

Y ejecutarlo desde la maquina local cada vez que se deploya.


## 10. Pasar de subdirectorio a subdominio

1. Crear el subdominio `mantenimiento.vogelconsultoria.com.ar` desde el panel
   de Ferozo (DNS + vhost).
2. Esperar a que el certificado TLS este emitido.
3. Crear el symlink (o configurar el vhost para apuntar a
   `mantenimiento-app/public/`).
4. Verificar con `curl -I https://mantenimiento.vogelconsultoria.com.ar/`.
5. Opcional: dejar un redirect permanente en el subdirectorio viejo hacia
   el subdominio. Para esto, agregar al `.htaccess` de Ferozo del
   subdirectorio una regla de reescritura que apunte al subdominio nuevo.
   El formato exacto lo da el panel de Ferozo o un ejemplo clasico de
   Apache: `RewriteRule ^(.*)$ https://mantenimiento.vogelconsultoria.com.ar/$1 [R=301,L]`.

> **Importante:** si se hace el redirect, el `.htaccess` de Ferozo del
> subdirectorio debe ser **distinto** del `.htaccess` de la app (porque ese
> fuerza HTTPS pero no debe reescribir a index.php). En la practica conviene
> no hacer el redirect automatico y dejar que los usuarios migren por si
> solos.


## 11. Como volver atras un deploy

Si algo sale mal despues de un deploy:

1. `cd /home/<usuario>/mantenimiento-app && git checkout <sha-anterior>`
2. Si la base de datos cambio, restaurar el backup correspondiente.
3. `chmod -R 755 writable`

La estructura con symlinks hace que el cambio a una version anterior de
codigo sea instantaneo.


## 12. Backup recomendado

En el panel de Ferozo, configurar backup automatico:

- Base de datos: diaria, retencion 14 dias.
- Carpeta `writable/uploads/` y `uploads.privatePath/`: semanal.

El backup de codigo lo da Git, no hace falta.


## 13. Problemas frecuentes


| Sintoma | Causa probable | Solucion |
|---|---|---|
| 500 inmediato | `writable/` sin permisos de escritura | `chmod -R 755 writable` |
| 500 al cargar | `.env` no existe o tiene formato invalido | `cp .env.example .env` y completar |

| Pantalla en blanco | PHP 7.x o extension intl faltante | Confirmar php -v y php -m en el server |

| Assets no cargan | El enlace a public/ no resuelve | Confirmar el enlace en el servidor |

| CSS no carga | baseURL mal calculado | Forzar pp.baseURL en .env |

| Login no funciona | ncryption.key vacia | Correr php spark key:generate |

| Correos no salen | SMTP mal configurado | Confirmar mail.* en .env y revisar los logs |

| Cron no corre | PHP CLI no esta en PATH o la version no es la correcta | Usar la ruta absoluta de PHP en el comando |

