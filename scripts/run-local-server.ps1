[CmdletBinding()]
param(
    [Alias('Host')]
    [string]$BindHost = '127.0.0.1',
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
$phpArguments = @()

foreach ($extension in @('zip', 'gd')) {
    $loaded = & $phpExecutable -r "echo extension_loaded('$extension') ? '1' : '0';"
    if ($loaded -ne '1') {
        $phpArguments += @('-d', "extension=$extension")
    }
}

$listen = '{0}:{1}' -f $BindHost, $Port
$phpArguments += @('-S', $listen, 'index.php')
Write-Host ("Servidor local: http://{0}" -f $listen) -ForegroundColor Green
if ($BindHost -eq '0.0.0.0') {
    Write-Host 'LAN habilitada: usá la IPv4 de esta PC en app.baseURL y desde el celular.' -ForegroundColor Yellow
}
Write-Host 'Las extensiones zip y gd se habilitan solo para este proceso cuando hace falta.' -ForegroundColor DarkGray

Push-Location $projectRoot
try {
    & $phpExecutable @phpArguments
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
