[CmdletBinding()]
param(
    [string]$SshTarget = 'fasa_195',
    [string]$RemotePath = '/home/ferreteria/mantenimiento-staging'
)

$ErrorActionPreference = 'Stop'

if ($RemotePath -ne '/home/ferreteria/mantenimiento-staging') {
    throw "Ruta remota no permitida para staging: $RemotePath"
}

$sshExecutable = (Get-Command ssh.exe -ErrorAction Stop).Source
$remoteScript = @'
set -eu

remote_path='__REMOTE_PATH__'
if [ "$remote_path" != "/home/ferreteria/mantenimiento-staging" ]; then
    echo "STAGING_PREFLIGHT=FAIL ruta remota no permitida" >&2
    exit 10
fi

cd "$remote_path"

echo "HOST=$(hostname)"
if [ ! -f docker-compose.yml ] || [ ! -f .env.docker ]; then
    echo "STAGING_PREFLIGHT=FAIL faltan docker-compose.yml o .env.docker" >&2
    exit 11
fi

read_ai_setting() {
    awk -v wanted="$1" '
        {
            line = $0
            sub(/^[[:space:]]*/, "", line)
            key = line
            sub(/[[:space:]]*=.*$/, "", key)
            if (key == wanted) {
                value = line
                sub(/^[^=]*=[[:space:]]*/, "", value)
                sub(/[[:space:]]*$/, "", value)
                print value
                exit
            }
        }
    ' "$2"
}

ai_enabled="$(read_ai_setting ai.enabled .env.docker | tr '[:upper:]' '[:lower:]')"
ai_provider="$(read_ai_setting ai.provider .env.docker)"
ai_api_key="$(read_ai_setting ai.apiKey .env.docker)"
ai_model="$(read_ai_setting ai.model .env.docker)"

if [ -z "$ai_enabled" ]; then
    echo "STAGING_PREFLIGHT=FAIL falta ai.enabled en .env.docker" >&2
    exit 20
fi
if [ "$ai_enabled" != "true" ] && [ "$ai_enabled" != "false" ]; then
    echo "STAGING_PREFLIGHT=FAIL ai.enabled debe ser true o false" >&2
    exit 20
fi
if [ -z "$ai_provider" ]; then
    echo "STAGING_PREFLIGHT=FAIL falta ai.provider en .env.docker" >&2
    exit 20
fi
if [ -z "$ai_model" ]; then
    echo "STAGING_PREFLIGHT=FAIL falta ai.model en .env.docker" >&2
    exit 20
fi
if [ "$ai_enabled" = "true" ] && [ -z "$ai_api_key" ]; then
    echo "STAGING_PREFLIGHT=FAIL ai.apiKey ausente o vacia con ai.enabled=true" >&2
    exit 20
fi

docker compose config --quiet
if ! docker compose config --services | grep -qx 'web'; then
    echo "STAGING_PREFLIGHT=FAIL servicio web ausente" >&2
    exit 12
fi

if ! command -v docker >/dev/null 2>&1 || [ ! -f frontend/package.json ] || [ ! -f frontend/package-lock.json ]; then
    echo "STAGING_PREFLIGHT=FAIL falta Docker o el lockfile de frontend" >&2
    exit 13
fi

if [ "$ai_enabled" = "true" ]; then
    ai_key_status=CONFIGURADA
else
    ai_key_status=NO_REQUERIDA
fi
echo "STAGING_PREFLIGHT=PASS ai.enabled=$ai_enabled ai.provider=$ai_provider ai.model=$ai_model ai.apiKey=$ai_key_status"

echo "STAGING_ASSETS=BUILD frontend"
docker run --rm --user "$(id -u):$(id -g)" \
    -v "$PWD/frontend:/src/frontend:ro" \
    -v "$PWD/assets/dashboard:/out" \
    node:22-bookworm-slim sh -ec '
        set -eu
        rm -rf /tmp/mantenimiento-build
        mkdir -p /tmp/mantenimiento-build/frontend
        tar -C /src/frontend --exclude=node_modules -cf - . | tar -C /tmp/mantenimiento-build/frontend -xf -
        cd /tmp/mantenimiento-build/frontend
        npm ci --no-audit --no-fund
        npm run build
        find /out -mindepth 1 -maxdepth 1 -exec rm -rf {} +
        cp -a /tmp/mantenimiento-build/assets/dashboard/. /out/
    '

required_brand_assets="assets/brand/logo-mark.svg assets/brand/icon-equipment.svg assets/brand/icon-services.svg assets/brand/icon-reports.svg"
for asset in $required_brand_assets; do
    if [ ! -f "$asset" ]; then
        echo "STAGING_ASSETS=FAIL falta $asset en el checkout" >&2
        exit 30
    fi
done

manifest="assets/dashboard/.vite/manifest.json"
if [ ! -f "$manifest" ]; then
    echo "STAGING_ASSETS=FAIL falta $manifest" >&2
    exit 31
fi
for file in $(grep -o '"file"[[:space:]]*:[[:space:]]*"[^"]*"' "$manifest" | sed -E 's/.*"([^"]+)"/\1/'); do
    if [ ! -f "assets/dashboard/$file" ]; then
        echo "STAGING_ASSETS=FAIL manifest apunta a $file inexistente" >&2
        exit 32
    fi
done
echo "STAGING_ASSETS=PASS brand y manifest construidos desde frontend"

docker compose build --no-cache web
docker compose up -d --force-recreate --no-deps web

if ! docker compose exec -T web sh -c '
    set -eu
    for asset in assets/brand/logo-mark.svg assets/brand/icon-equipment.svg assets/brand/icon-services.svg assets/brand/icon-reports.svg; do
        test -f "/var/www/html/$asset"
    done
    read_ai_setting() {
        awk -v wanted="$1" '\''
            {
                line = $0
                sub(/^[[:space:]]*/, "", line)
                key = line
                sub(/[[:space:]]*=.*$/, "", key)
                if (key == wanted) {
                    value = line
                    sub(/^[^=]*=[[:space:]]*/, "", value)
                    sub(/[[:space:]]*$/, "", value)
                    print value
                    exit
                }
            }
        '\'' /var/www/html/.env
    }
    enabled="$(read_ai_setting ai.enabled | tr "[:upper:]" "[:lower:]")"
    provider="$(read_ai_setting ai.provider)"
    api_key="$(read_ai_setting ai.apiKey)"
    model="$(read_ai_setting ai.model)"
    [ -n "$enabled" ] && [ -n "$provider" ] && [ -n "$model" ]
    [ "$enabled" != "true" ] || [ -n "$api_key" ]
'; then
    echo "STAGING_DEPLOY=FAIL faltan assets brand o configuracion ai.* en el contenedor" >&2
    exit 21
fi

echo "STAGING_DEPLOY=PASS web recreado; brand, manifest y configuracion ai.* disponibles"
'@
$remoteScript = $remoteScript.Replace('__REMOTE_PATH__', $RemotePath)
$remoteScript = $remoteScript.TrimStart([char]0xFEFF)

Write-Host "== Deploy staging: preflight y reconstruccion en $SshTarget ==" -ForegroundColor Cyan
$payload = [Convert]::ToBase64String([System.Text.UTF8Encoding]::new($false).GetBytes($remoteScript))
& $sshExecutable $SshTarget "echo $payload | base64 -d | bash -s"
if ($LASTEXITCODE -ne 0) {
    throw "El deploy de staging fue detenido por el preflight o fallo remoto (codigo $LASTEXITCODE)."
}
