#!/bin/sh
set -e

# Asegurar permisos en volumes montados por Coolify (writable + /data/priv)
mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar
mkdir -p /data/priv/adjuntos /data/priv/importaciones
chown -R www-data:www-data writable /data 2>/dev/null || true
chmod -R 755 writable 2>/dev/null || true

# Apache/PHP no conserva en sus hijos las variables de entorno con puntos que
# Coolify inyecta (por ejemplo, database.default.hostname). CI4 sí las lee
# desde .env, por lo que se materializa un archivo efímero con las variables
# runtime antes de iniciar Apache. El archivo queda dentro del contenedor y
# nunca forma parte de la imagen ni del repositorio.
runtime_env_file="${PWD}/.env"
runtime_env_tmp="${runtime_env_file}.tmp"
umask 027
: > "$runtime_env_tmp"

for key in $(env | cut -d= -f1 | grep -E '^(CI_ENVIRONMENT|[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z0-9_.]+)$' | sort -u); do
    value=$(printenv "$key" 2>/dev/null || true)
    escaped=$(printf '%s' "$value" | sed 's/\\/\\\\/g; s/"/\\"/g')
    printf '%s="%s"\n' "$key" "$escaped" >> "$runtime_env_tmp"
done

if [ -s "$runtime_env_tmp" ]; then
    mv "$runtime_env_tmp" "$runtime_env_file"
    chown root:www-data "$runtime_env_file" 2>/dev/null || true
    chmod 640 "$runtime_env_file" 2>/dev/null || true
else
    rm -f "$runtime_env_tmp"
fi

# Opcional: en staging no auto-migrar para evitar race con deploy.
# Coolify puede ejecutar manualmente: docker exec <app> php spark migrate

exec "$@"
