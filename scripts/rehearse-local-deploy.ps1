[CmdletBinding()]
param(
    [int]$HttpPort = 8081,
    [string]$DatabaseHost = '127.0.0.1',
    [int]$DatabasePort = 3306,
    [string]$DatabaseUser = 'root',
    [string]$DatabasePassword = '',
    [string]$MySqlExecutable = 'C:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
$composerExecutable = (Get-Command composer -ErrorAction Stop).Source
$runId = [guid]::NewGuid().ToString('N').Substring(0, 8)
$databaseName = "mantenimiento_phase0a_$runId"
$temporaryBase = [System.IO.Path]::GetTempPath().TrimEnd('\')
$rehearsalRoot = Join-Path $temporaryBase "mantenimiento-phase0a-$runId"
$stagingPath = Join-Path $rehearsalRoot 'staging'
$releaseAPath = Join-Path $rehearsalRoot 'release-a'
$releaseBPath = Join-Path $rehearsalRoot 'release-b'
$livePath = Join-Path $rehearsalRoot 'live'
$rollbackPath = Join-Path $rehearsalRoot 'rollback'
$archivePath = Join-Path $projectRoot "writable\cache\mantenimiento-phase0a-$runId.zip"
$httpProcess = $null
$databaseCreated = $false
$startedAt = Get-Date

if ($databaseName -notmatch '^mantenimiento_phase0a_[a-f0-9]{8}$') {
    throw "Nombre de base temporal inseguro: $databaseName"
}
if (-not $rehearsalRoot.StartsWith($temporaryBase + '\', [System.StringComparison]::OrdinalIgnoreCase) `
    -or (Split-Path $rehearsalRoot -Leaf) -notmatch '^mantenimiento-phase0a-[a-f0-9]{8}$') {
    throw "Ruta temporal insegura: $rehearsalRoot"
}
if (-not (Test-Path -LiteralPath $MySqlExecutable)) {
    throw "No se encontró el cliente MySQL: $MySqlExecutable"
}

function Invoke-External {
    param(
        [Parameter(Mandatory)] [string]$FilePath,
        [Parameter(Mandatory)] [string[]]$ArgumentList
    )

    & $FilePath @ArgumentList
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath finalizó con código $LASTEXITCODE."
    }
}

function Invoke-Robocopy {
    param(
        [Parameter(Mandatory)] [string[]]$ArgumentList
    )

    & robocopy.exe @ArgumentList | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy finalizó con código $LASTEXITCODE."
    }
}

function Invoke-MySql {
    param(
        [Parameter(Mandatory)] [string]$Sql
    )

    $arguments = @(
        '--protocol=tcp',
        '-h', $DatabaseHost,
        '-P', [string]$DatabasePort,
        '-u', $DatabaseUser
    )
    if ($DatabasePassword -ne '') {
        $arguments += "-p$DatabasePassword"
    }
    $arguments += @('-e', $Sql)
    Invoke-External $MySqlExecutable $arguments
}

function Wait-ForHttp {
    param(
        [Parameter(Mandatory)] [string]$Url,
        [int]$TimeoutSeconds = 15
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing
            if ($response.StatusCode -eq 200) {
                return $response
            }
        } catch {
            Start-Sleep -Milliseconds 250
        }
    } until ((Get-Date) -ge $deadline)

    throw "El endpoint $Url no respondió 200 dentro del plazo."
}

function Start-ReleaseServer {
    param(
        [Parameter(Mandatory)] [string]$ReleasePath
    )

    $listener = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue
    if ($listener) {
        throw "El puerto $HttpPort ya está ocupado."
    }

    return Start-Process -FilePath $phpExecutable -ArgumentList @(
        '-S', "127.0.0.1:$HttpPort", 'index.php'
    ) -WorkingDirectory $ReleasePath -WindowStyle Hidden -PassThru
}

function Assert-LoginFlow {
    param(
        [Parameter(Mandatory)] [string]$BaseUrl
    )

    $webSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $loginResponse = Invoke-WebRequest -Uri ($BaseUrl + 'login') -UseBasicParsing -WebSession $webSession
    $csrfMatch = [regex]::Match($loginResponse.Content, 'name="csrf_test_name"\s+value="([^"]+)"')
    if ($loginResponse.StatusCode -ne 200 -or -not $csrfMatch.Success) {
        throw 'La instalación limpia no entregó login y CSRF válidos.'
    }

    $dashboardResponse = Invoke-WebRequest -Uri ($BaseUrl + 'login/authenticate') `
        -Method Post `
        -Body @{
            email = 'admin@mantenimiento.local'
            password = 'Admin1234'
            csrf_test_name = $csrfMatch.Groups[1].Value
        } `
        -ContentType 'application/x-www-form-urlencoded' `
        -UseBasicParsing `
        -WebSession $webSession

    if ($dashboardResponse.StatusCode -ne 200 `
        -or $dashboardResponse.BaseResponse.ResponseUri.AbsolutePath -ne '/dashboard') {
        throw 'La instalación limpia no completó el login.'
    }
}

New-Item -ItemType Directory -Path $stagingPath -Force | Out-Null

try {
    Write-Host '== Ensayo deploy: preparar staging ==' -ForegroundColor Cyan
    Invoke-Robocopy @(
        $projectRoot,
        $stagingPath,
        '/E',
        '/XD',
        (Join-Path $projectRoot '.git'),
        (Join-Path $projectRoot 'vendor'),
        (Join-Path $projectRoot 'build'),
        (Join-Path $projectRoot 'dist'),
        (Join-Path $projectRoot 'writable\cache'),
        (Join-Path $projectRoot 'writable\logs'),
        (Join-Path $projectRoot 'writable\session'),
        (Join-Path $projectRoot 'writable\uploads'),
        (Join-Path $projectRoot 'writable\debugbar'),
        '/XF', '.env', '.ferozo-credentials*',
        '/NFL', '/NDL', '/NJH', '/NJS', '/NP'
    )

    foreach ($runtimeDirectory in @('cache', 'logs', 'session', 'uploads', 'debugbar')) {
        $targetDirectory = Join-Path $stagingPath "writable\$runtimeDirectory"
        New-Item -ItemType Directory -Path $targetDirectory -Force | Out-Null
        Copy-Item -LiteralPath (Join-Path $projectRoot "writable\$runtimeDirectory\index.html") `
            -Destination (Join-Path $targetDirectory 'index.html')
    }

    if (Test-Path -LiteralPath (Join-Path $stagingPath '.env')) {
        throw 'El staging incluyó .env.'
    }

    Push-Location $stagingPath
    try {
        Invoke-External $composerExecutable @('install', '--no-dev', '--no-interaction', '--optimize-autoloader')
    } finally {
        Pop-Location
    }

    Write-Host '== Ensayo deploy: construir y extraer paquete ==' -ForegroundColor Cyan
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $stagingPath,
        $archivePath,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $false
    )
    [System.IO.Compression.ZipFile]::ExtractToDirectory($archivePath, $releaseAPath)

    if (-not (Test-Path -LiteralPath (Join-Path $releaseAPath '.htaccess')) `
        -or -not (Test-Path -LiteralPath (Join-Path $releaseAPath 'vendor\autoload.php')) `
        -or -not (Test-Path -LiteralPath (Join-Path $releaseAPath 'spark'))) {
        throw 'El paquete no contiene .htaccess, vendor o spark.'
    }

    Write-Host '== Ensayo deploy: base limpia, migraciones y seeder ==' -ForegroundColor Cyan
    Invoke-MySql "CREATE DATABASE $databaseName CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    $databaseCreated = $true

    [Environment]::SetEnvironmentVariable('CI_ENVIRONMENT', 'development', 'Process')
    [Environment]::SetEnvironmentVariable('app.baseURL', "http://127.0.0.1:$HttpPort/", 'Process')
    [Environment]::SetEnvironmentVariable('database.default.hostname', $DatabaseHost, 'Process')
    [Environment]::SetEnvironmentVariable('database.default.database', $databaseName, 'Process')
    [Environment]::SetEnvironmentVariable('database.default.username', $DatabaseUser, 'Process')
    [Environment]::SetEnvironmentVariable('database.default.password', $DatabasePassword, 'Process')
    [Environment]::SetEnvironmentVariable('database.default.DBDriver', 'MySQLi', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.port', [string]$DatabasePort, 'Process')

    Push-Location $releaseAPath
    try {
        Invoke-External $phpExecutable @('spark', 'migrate', '--all')
        Invoke-External $phpExecutable @('spark', 'db:seed', 'InitialSeeder')
        Invoke-External $phpExecutable @('spark', 'hosting:check')
    } finally {
        Pop-Location
    }

    Write-Host '== Ensayo deploy: smoke HTTP de instalación limpia ==' -ForegroundColor Cyan
    $httpProcess = Start-ReleaseServer $releaseAPath
    $baseUrl = "http://127.0.0.1:$HttpPort/"
    Wait-ForHttp -Url $baseUrl | Out-Null
    Assert-LoginFlow -BaseUrl $baseUrl
    Stop-Process -Id $httpProcess.Id -Force
    $httpProcess = $null

    Write-Host '== Ensayo deploy: actualización preservando runtime ==' -ForegroundColor Cyan
    Invoke-Robocopy @($releaseAPath, $livePath, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP')
    Set-Content -LiteralPath (Join-Path $livePath '.env') -Value '# sentinel local phase0a' -Encoding UTF8
    Set-Content -LiteralPath (Join-Path $livePath 'writable\cache\preserve.txt') -Value $runId -Encoding UTF8
    $envHash = (Get-FileHash -LiteralPath (Join-Path $livePath '.env') -Algorithm SHA256).Hash

    Invoke-Robocopy @(
        $livePath, $rollbackPath, '/E',
        '/XD', (Join-Path $livePath 'writable'),
        '/XF', '.env',
        '/NFL', '/NDL', '/NJH', '/NJS', '/NP'
    )
    [System.IO.Compression.ZipFile]::ExtractToDirectory($archivePath, $releaseBPath)
    Set-Content -LiteralPath (Join-Path $releaseBPath 'phase0a-release-b.txt') -Value $runId -Encoding UTF8

    Invoke-Robocopy @(
        $releaseBPath, $livePath, '/MIR',
        '/XD', (Join-Path $releaseBPath 'writable'),
        '/XF', '.env',
        '/NFL', '/NDL', '/NJH', '/NJS', '/NP'
    )
    if (-not (Test-Path -LiteralPath (Join-Path $livePath 'phase0a-release-b.txt'))) {
        throw 'La actualización no aplicó el marcador de release B.'
    }
    if ((Get-FileHash -LiteralPath (Join-Path $livePath '.env') -Algorithm SHA256).Hash -ne $envHash `
        -or (Get-Content -Raw -LiteralPath (Join-Path $livePath 'writable\cache\preserve.txt')).Trim() -ne $runId) {
        throw 'La actualización modificó .env o writable.'
    }

    Write-Host '== Ensayo deploy: rollback ==' -ForegroundColor Cyan
    Invoke-Robocopy @(
        $rollbackPath, $livePath, '/MIR',
        '/XD', (Join-Path $livePath 'writable'),
        '/XF', '.env',
        '/NFL', '/NDL', '/NJH', '/NJS', '/NP'
    )
    if (Test-Path -LiteralPath (Join-Path $livePath 'phase0a-release-b.txt')) {
        throw 'El rollback no retiró el marcador de release B.'
    }
    if ((Get-FileHash -LiteralPath (Join-Path $livePath '.env') -Algorithm SHA256).Hash -ne $envHash `
        -or (Get-Content -Raw -LiteralPath (Join-Path $livePath 'writable\cache\preserve.txt')).Trim() -ne $runId) {
        throw 'El rollback modificó .env o writable.'
    }

    $httpProcess = Start-ReleaseServer $livePath
    Wait-ForHttp -Url $baseUrl | Out-Null
    Assert-LoginFlow -BaseUrl $baseUrl

    $elapsed = [math]::Round(((Get-Date) - $startedAt).TotalSeconds, 2)
    $archiveSizeMb = [math]::Round((Get-Item -LiteralPath $archivePath).Length / 1MB, 2)
    Write-Host "ENSAYO DE DEPLOY APROBADO en $elapsed segundos." -ForegroundColor Green
    Write-Host "Paquete: $archivePath ($archiveSizeMb MB)"
    Write-Host 'Instalación limpia, actualización, preservación de runtime y rollback: PASS'
}
finally {
    if ($httpProcess -and -not $httpProcess.HasExited) {
        Stop-Process -Id $httpProcess.Id -Force
    }

    if ($databaseCreated -and $databaseName -match '^mantenimiento_phase0a_[a-f0-9]{8}$') {
        Invoke-MySql "DROP DATABASE $databaseName;"
    }

    if (Test-Path -LiteralPath $rehearsalRoot) {
        Remove-Item -LiteralPath $rehearsalRoot -Recurse -Force
    }
}
