[CmdletBinding()]
param(
    [int]$HttpPort = 8080,
    [int]$SmtpPort = 1025,
    [string]$EmailRecipient = 'destino@example.test',
    [switch]$SkipTaskScheduler
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
$pythonExecutable = (Get-Command python -ErrorAction Stop).Source
$composerExecutable = (Get-Command composer -ErrorAction Stop).Source
$sparkPath = Join-Path $projectRoot 'spark'
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$smtpCapturePath = Join-Path $projectRoot "writable\cache\phase0a-email-$timestamp.eml"
$probeId = "phase0a-$timestamp"
$taskName = "Mantenimiento-Phase0A-$PID"
$smtpProcess = $null
$httpProcess = $null
$taskCreated = $false
$startedAt = Get-Date

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

function Wait-ForTcpPort {
    param(
        [Parameter(Mandatory)] [int]$Port,
        [int]$TimeoutSeconds = 15
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $listener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
        if ($listener) {
            return $true
        }
        Start-Sleep -Milliseconds 250
    } until ((Get-Date) -ge $deadline)

    return $false
}

Push-Location $projectRoot
try {
    Write-Host '== Fase 0A: preflight ==' -ForegroundColor Cyan
    Invoke-External $composerExecutable @('validate', '--strict')
    Invoke-External $phpExecutable @('spark', 'hosting:check')

    Write-Host '== Fase 0A: SMTP local ==' -ForegroundColor Cyan
    $smtpProcess = Start-Process -FilePath $pythonExecutable -ArgumentList @(
        'scripts\smtp_capture.py',
        '--host', '127.0.0.1',
        '--port', [string]$SmtpPort,
        '--output', $smtpCapturePath
    ) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru

    if (-not (Wait-ForTcpPort -Port $SmtpPort)) {
        throw "El SMTP de captura no abrió el puerto $SmtpPort."
    }

    [Environment]::SetEnvironmentVariable('email.protocol', 'smtp', 'Process')
    [Environment]::SetEnvironmentVariable('email.SMTPHost', '127.0.0.1', 'Process')
    [Environment]::SetEnvironmentVariable('email.SMTPPort', [string]$SmtpPort, 'Process')
    [Environment]::SetEnvironmentVariable('email.SMTPUser', '', 'Process')
    [Environment]::SetEnvironmentVariable('email.SMTPPass', '', 'Process')
    [Environment]::SetEnvironmentVariable('email.fromEmail', 'phase0a@mantenimiento.local', 'Process')
    [Environment]::SetEnvironmentVariable('email.fromName', 'Mantenimiento Fase 0A', 'Process')

    Invoke-External $phpExecutable @(
        'spark',
        'hosting:check',
        '--email', $EmailRecipient,
        '--smtp-plaintext'
    )

    $smtpProcess.WaitForExit(5000) | Out-Null
    if (-not (Test-Path -LiteralPath $smtpCapturePath)) {
        throw 'El SMTP aceptó el comando pero no se generó el archivo de captura.'
    }

    $capturedMessage = Get-Content -Raw -LiteralPath $smtpCapturePath
    if ($capturedMessage -notmatch [regex]::Escape($EmailRecipient) -or $capturedMessage -notmatch 'phase0a-email-') {
        throw 'La captura SMTP no contiene el destinatario y token esperados.'
    }

    Write-Host '== Fase 0A: comando programable ==' -ForegroundColor Cyan
    Invoke-External $phpExecutable @('spark', 'cron:probe', '--id', $probeId)
    $cronLogPath = Join-Path $projectRoot 'writable\logs\cron-probe.log'
    if (-not (Select-String -Path $cronLogPath -Pattern $probeId -SimpleMatch -Quiet)) {
        throw 'El cron probe no dejó evidencia en writable/logs/cron-probe.log.'
    }

    if (-not $SkipTaskScheduler) {
        Write-Host '== Fase 0A: Programador de tareas de Windows ==' -ForegroundColor Cyan
        $scheduledProbeId = "$probeId-scheduled"
        $taskAction = '"' + $phpExecutable + '" "' + $sparkPath + '" cron:probe --id ' + $scheduledProbeId
        $startTime = (Get-Date).AddMinutes(5).ToString('HH:mm')

        Invoke-External 'schtasks.exe' @(
            '/Create', '/TN', $taskName,
            '/TR', $taskAction,
            '/SC', 'ONCE', '/ST', $startTime,
            '/F'
        )
        $taskCreated = $true
        Invoke-External 'schtasks.exe' @('/Run', '/TN', $taskName)

        $deadline = (Get-Date).AddSeconds(15)
        do {
            Start-Sleep -Milliseconds 500
            $scheduledFound = Select-String -Path $cronLogPath -Pattern $scheduledProbeId -SimpleMatch -Quiet
        } until ($scheduledFound -or (Get-Date) -ge $deadline)

        if (-not $scheduledFound) {
            throw 'La tarea de Windows no dejó la evidencia esperada.'
        }

        Invoke-External 'schtasks.exe' @('/Delete', '/TN', $taskName, '/F')
        $taskCreated = $false
    }

    Write-Host '== Fase 0A: pruebas automatizadas ==' -ForegroundColor Cyan
    Invoke-External $composerExecutable @('test')

    Write-Host '== Fase 0A: smoke HTTP y sesión ==' -ForegroundColor Cyan
    $baseUrl = "http://127.0.0.1:$HttpPort/"
    $existingListener = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue
    if (-not $existingListener) {
        [Environment]::SetEnvironmentVariable('app.baseURL', $baseUrl, 'Process')
        $httpProcess = Start-Process -FilePath $phpExecutable -ArgumentList @(
            '-S', "127.0.0.1:$HttpPort", 'index.php'
        ) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru

        if (-not (Wait-ForTcpPort -Port $HttpPort)) {
            throw "El servidor PHP no abrió el puerto $HttpPort."
        }
    }

    $webSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $rootResponse = Invoke-WebRequest -Uri $baseUrl -UseBasicParsing -WebSession $webSession
    $loginResponse = Invoke-WebRequest -Uri ($baseUrl + 'login') -UseBasicParsing -WebSession $webSession
    $csrfMatch = [regex]::Match($loginResponse.Content, 'name="csrf_test_name"\s+value="([^"]+)"')

    if ($rootResponse.StatusCode -ne 200 -or $loginResponse.StatusCode -ne 200 -or -not $csrfMatch.Success) {
        throw 'Falló el smoke público o no se encontró el token CSRF.'
    }

    $loginBody = @{
        email = 'admin@mantenimiento.local'
        password = 'Admin1234'
        csrf_test_name = $csrfMatch.Groups[1].Value
    }
    $dashboardResponse = Invoke-WebRequest -Uri ($baseUrl + 'login/authenticate') `
        -Method Post `
        -Body $loginBody `
        -ContentType 'application/x-www-form-urlencoded' `
        -UseBasicParsing `
        -WebSession $webSession

    if ($dashboardResponse.StatusCode -ne 200 `
        -or $dashboardResponse.BaseResponse.ResponseUri.AbsolutePath -ne '/dashboard' `
        -or $dashboardResponse.Content -notmatch 'Administrador') {
        throw 'El login no conservó la sesión hasta el dashboard.'
    }

    $elapsed = [math]::Round(((Get-Date) - $startedAt).TotalSeconds, 2)
    Write-Host "FASE 0A APROBADA en $elapsed segundos." -ForegroundColor Green
    Write-Host "Captura SMTP: $smtpCapturePath"
    Write-Host "Cron log: $cronLogPath"
}
finally {
    if ($taskCreated) {
        & schtasks.exe /Delete /TN $taskName /F | Out-Null
    }
    if ($smtpProcess -and -not $smtpProcess.HasExited) {
        Stop-Process -Id $smtpProcess.Id -Force
    }
    if ($httpProcess -and -not $httpProcess.HasExited) {
        Stop-Process -Id $httpProcess.Id -Force
    }
    Pop-Location
}
