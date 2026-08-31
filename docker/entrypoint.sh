#!/bin/sh
set -e

# Asegurar permisos en volumes montados por Coolify (writable + /data/priv)
mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar
mkdir -p /data/priv/adjuntos /data/priv/importaciones
chown -R www-data:www-data writable /data 2>/dev/null || true
chmod -R 755 writable 2>/dev/null || true

# Si uploads.privatePath / imports.privatePath apuntan a /data/priv, el
# LocalPrivate*Storage ya los usa. No se toca .env.

# Opcional: en staging no auto-migrar para evitar race con deploy.
# Coolify puede ejecutar manualmente: docker exec <app> php spark migrate

exec "$@"
