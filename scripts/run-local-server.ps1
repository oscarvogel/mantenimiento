[CmdletBinding()]
param(
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

$phpArguments += @('-S', "127.0.0.1:$Port", 'index.php')
Write-Host "Servidor local: http://127.0.0.1:$Port" -ForegroundColor Green
Write-Host 'Las extensiones zip y gd se habilitan solo para este proceso cuando hace falta.' -ForegroundColor DarkGray

Push-Location $projectRoot
try {
    & $phpExecutable @phpArguments
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
