[CmdletBinding()]
param(
    [int]$HttpPort = 8084,
    [string]$MySqlExecutable = 'C:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runId = [guid]::NewGuid().ToString('N').Substring(0, 8)
$databaseName = "mantenimiento_fase2b_$runId"
$temporaryBase = [System.IO.Path]::GetTempPath().TrimEnd('\')
$temporaryRoot = Join-Path $temporaryBase "mantenimiento-fase2b-$runId"
$server = $null
$databaseCreated = $false
$savedDottedEnvironment = @{}
$dottedEnvironmentKeys = @(
    'CI_ENVIRONMENT',
    'app.baseURL',
    'database.default.hostname',
    'database.default.database',
    'database.default.username',
    'database.default.password',
    'database.default.DBDriver',
    'database.default.port'
)

if ($databaseName -notmatch '^mantenimiento_fase2b_[a-f0-9]{8}$') {
    throw "Nombre de base temporal inseguro: $databaseName"
}
if (-not $temporaryRoot.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
    -or (Split-Path $temporaryRoot -Leaf) -notmatch '^mantenimiento-fase2b-[a-f0-9]{8}$') {
    throw "Ruta temporal insegura: $temporaryRoot"
}
if (-not (Test-Path -LiteralPath $MySqlExecutable)) {
    throw "No se encontró MySQL: $MySqlExecutable"
}
if (Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue) {
    throw "El puerto HTTP $HttpPort ya está ocupado. Elegí otro con -HttpPort."
}

function Invoke-MySql {
    param([Parameter(Mandatory)] [string]$Sql, [switch]$WithoutDatabase)

    $arguments = @('--protocol=tcp', '-h', '127.0.0.1', '-P', '3306', '-u', 'root', '-N')
    if (-not $WithoutDatabase) {
        $arguments += @('-D', $databaseName)
    }
    $arguments += @('-e', $Sql)
    $output = & $MySqlExecutable @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Falló la operación sobre la base MariaDB temporal.'
    }

    return $output
}

function Invoke-MySqlScalar {
    param([Parameter(Mandatory)] [string]$Sql)

    return ((Invoke-MySql $Sql | Select-Object -First 1) -as [string]).Trim()
}

function Get-CsrfToken {
    param([Parameter(Mandatory)] [string]$Html)

    $match = [regex]::Match($Html, 'name="csrf_test_name"\s+value="([^"]+)"')
    if ($match.Success) {
        return $match.Groups[1].Value
    }

    $payloadMatch = [regex]::Match(
        $Html,
        '<script\s+id="maintenance-app-data"\s+type="application/json">(?<json>.*?)</script>',
        [System.Text.RegularExpressions.RegexOptions]::Singleline
    )
    if ($payloadMatch.Success) {
        $payload = $payloadMatch.Groups['json'].Value | ConvertFrom-Json
        $hash = $payload.data.csrf.hash
        if (-not [string]::IsNullOrWhiteSpace([string] $hash)) {
            return [string] $hash
        }
    }

    if (-not $match.Success) {
        throw 'No se encontró el token CSRF en la respuesta.'
    }

    return $match.Groups[1].Value
}

function New-AuthenticatedSession {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest "http://127.0.0.1:$HttpPort/login" `
        -UseBasicParsing -WebSession $session
    $response = Invoke-WebRequest "http://127.0.0.1:$HttpPort/login/authenticate" `
        -Method Post `
        -Body @{
            email = 'admin@mantenimiento.local'
            password = 'Admin1234'
            csrf_test_name = Get-CsrfToken $login.Content
        } `
        -ContentType 'application/x-www-form-urlencoded' `
        -UseBasicParsing -WebSession $session
    if ($response.Content -notmatch 'Administrador') {
        throw 'No se pudo autenticar el Administrador en la instalación temporal.'
    }

    return $session
}

function Invoke-Form {
    param(
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [hashtable]$Fields,
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$CsrfHtml
    )

    $body = @{}
    foreach ($entry in $Fields.GetEnumerator()) {
        $body[$entry.Key] = $entry.Value
    }
    $body['csrf_test_name'] = Get-CsrfToken $CsrfHtml

    return Invoke-WebRequest "http://127.0.0.1:$HttpPort$Path" `
        -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' `
        -UseBasicParsing -WebSession $Session
}

try {
    New-Item -ItemType Directory -Path $temporaryRoot -Force | Out-Null
    & robocopy.exe $source $temporaryRoot /E `
        /XD (Join-Path $source '.git') `
            (Join-Path $source 'writable\cache') `
            (Join-Path $source 'writable\logs') `
            (Join-Path $source 'writable\session') `
            (Join-Path $source 'writable\uploads') `
            (Join-Path $source 'writable\debugbar') `
        /XF '.env' /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "Robocopy finalizó con código $LASTEXITCODE."
    }

    foreach ($directory in @('cache', 'logs', 'session', 'uploads', 'debugbar')) {
        $runtimePath = Join-Path $temporaryRoot "writable\$directory"
        New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null
        $sourceIndex = Join-Path $source "writable\$directory\index.html"
        if (Test-Path -LiteralPath $sourceIndex) {
            Copy-Item -LiteralPath $sourceIndex -Destination (Join-Path $runtimePath 'index.html')
        }
    }

    Invoke-MySql "CREATE DATABASE $databaseName CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" `
        -WithoutDatabase | Out-Null
    $databaseCreated = $true

    # CodeIgniter da prioridad a variables de entorno sobre .env. Limpiamos y
    # luego restauramos solo estas claves para que un override punteado heredado
    # no pueda dirigir la prueba hacia la base principal.
    foreach ($key in $dottedEnvironmentKeys) {
        $savedDottedEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $null, 'Process')
    }

    $temporaryEnvironment = @"
CI_ENVIRONMENT = development
app.baseURL = 'http://127.0.0.1:$HttpPort/'
database.default.hostname = 127.0.0.1
database.default.database = $databaseName
database.default.username = root
database.default.password = ''
database.default.DBDriver = MySQLi
database.default.port = 3306
"@
    [System.IO.File]::WriteAllText(
        (Join-Path $temporaryRoot '.env'),
        $temporaryEnvironment,
        [System.Text.UTF8Encoding]::new($false)
    )

    Push-Location $temporaryRoot
    try {
        & php spark migrate --all
        if ($LASTEXITCODE -ne 0) { throw 'Fallaron las migraciones en la base temporal.' }
        & php spark db:seed InitialSeeder
        if ($LASTEXITCODE -ne 0) { throw 'Falló InitialSeeder en la base temporal.' }
    } finally {
        Pop-Location
    }
    # Algunas versiones del comando spark no propagan como exit code todos los
    # errores del seeder; verificamos sus efectos antes de levantar HTTP.
    if ((Invoke-MySqlScalar "SELECT COUNT(*) FROM usuarios WHERE email='admin@mantenimiento.local';") -ne '1' `
        -or (Invoke-MySqlScalar 'SELECT COUNT(*) FROM tipos_equipo WHERE activo=1;') -eq '0') {
        throw 'InitialSeeder no completó usuarios y catálogos en la base temporal.'
    }

    $server = Start-Process php `
        -ArgumentList @('-S', "127.0.0.1:$HttpPort", 'index.php') `
        -WorkingDirectory $temporaryRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $temporaryRoot 'writable\phase2b-http.out.log') `
        -RedirectStandardError (Join-Path $temporaryRoot 'writable\phase2b-http.err.log')
    $deadline = (Get-Date).AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 250
        $listener = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue
    } until ($listener -or (Get-Date) -ge $deadline)
    if (-not $listener) { throw 'El servidor HTTP temporal no inició.' }

    $session = New-AuthenticatedSession
    $companyId = Invoke-MySqlScalar "SELECT id FROM empresas ORDER BY id LIMIT 1;"
    $originBranchId = Invoke-MySqlScalar "SELECT id FROM sucursales WHERE empresa_id=$companyId ORDER BY id LIMIT 1;"
    $equipmentTypeId = Invoke-MySqlScalar "SELECT id FROM tipos_equipo WHERE activo=1 AND controla_km=1 ORDER BY id LIMIT 1;"
    if ($companyId -notmatch '^\d+$' -or $originBranchId -notmatch '^\d+$' -or $equipmentTypeId -notmatch '^\d+$') {
        throw 'No se obtuvieron los catálogos requeridos para la prueba.'
    }

    $circuit = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento" `
        -UseBasicParsing -WebSession $session
    $equipmentCode = "QA-$runId"
    $circuit = Invoke-Form '/mantenimiento/equipos' @{
        sucursal_id = $originBranchId
        tipo_equipo_id = $equipmentTypeId
        codigo = $equipmentCode
        patente = "QA$runId"
        fecha_alta = '2026-08-01'
        observaciones = 'Equipo temporal para aceptación Fase 2B'
    } $session $circuit.Content
    $equipmentId = Invoke-MySqlScalar "SELECT id FROM equipos WHERE empresa_id=$companyId AND codigo='$equipmentCode';"
    if ($equipmentId -notmatch '^\d+$') { throw 'No se creó el equipo temporal.' }

    $detail = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos/$equipmentId" `
        -UseBasicParsing -WebSession $session
    $detail = Invoke-Form "/mantenimiento/equipos/$equipmentId/lecturas" @{
        fecha_lectura = '2026-08-02 10:00:00'
        kilometraje = '1000'
        horometro = ''
        motivo_correccion = ''
        observaciones = 'Lectura original QA'
    } $session $detail.Content
    $originalReadingId = Invoke-MySqlScalar "SELECT id FROM lecturas_equipo WHERE empresa_id=$companyId AND equipo_id=$equipmentId ORDER BY id DESC LIMIT 1;"
    if ($originalReadingId -notmatch '^\d+$') { throw 'No se registró la lectura original.' }

    $updatedCode = "QA-EDIT-$runId"
    $detail = Invoke-Form "/mantenimiento/equipos/$equipmentId/editar" @{
        codigo = $updatedCode
        patente = "EDIT$runId"
        tipo_equipo_id = $equipmentTypeId
        fecha_alta = '2026-08-01'
        observaciones = 'Ficha actualizada por Fase 2B'
    } $session $detail.Content
    $profile = Invoke-MySqlScalar "SELECT CONCAT(codigo,':',patente) FROM equipos WHERE id=$equipmentId AND empresa_id=$companyId;"
    if ($profile -ne "$updatedCode`:EDIT$runId") { throw "La edición no se persistió: $profile" }

    # Una repetición idéntica debe ser segura y no convertir el no-op en error.
    $detail = Invoke-Form "/mantenimiento/equipos/$equipmentId/editar" @{
        codigo = $updatedCode
        patente = "EDIT$runId"
        tipo_equipo_id = $equipmentTypeId
        fecha_alta = '2026-08-01'
        observaciones = 'Ficha actualizada por Fase 2B'
    } $session $detail.Content
    if ($detail.Content -notmatch 'actualiz') { throw 'La edición idempotente no informó éxito.' }

    Invoke-MySql "INSERT INTO sucursales (empresa_id,codigo,nombre,estado,created_at,updated_at) VALUES ($companyId,'DST$runId','Destino QA',1,NOW(),NOW());" | Out-Null
    $destinationBranchId = Invoke-MySqlScalar "SELECT id FROM sucursales WHERE empresa_id=$companyId AND codigo='DST$runId';"
    $detail = Invoke-Form "/mantenimiento/equipos/$equipmentId/trasladar" @{
        sucursal_destino_id = $destinationBranchId
        fecha_traslado = '2026-08-03'
        motivo = 'Traslado QA entre sucursales'
    } $session $detail.Content
    $transferState = Invoke-MySqlScalar "SELECT CONCAT(e.sucursal_id,':',COUNT(h.id)) FROM equipos e LEFT JOIN equipo_sucursal_historial h ON h.empresa_id=e.empresa_id AND h.equipo_id=e.id WHERE e.id=$equipmentId AND e.empresa_id=$companyId GROUP BY e.sucursal_id;"
    if ($transferState -ne "$destinationBranchId`:1") { throw "El traslado o su historial falló: $transferState" }

    # El traslado no debe hacer desaparecer las lecturas de la sucursal origen.
    $detail = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos/$equipmentId" `
        -UseBasicParsing -WebSession $session
    if ($detail.Content -notmatch '1000' -or $detail.Content -notmatch 'Traslado QA entre sucursales') {
        throw 'La ficha no conserva el historial previo al traslado.'
    }

    $detail = Invoke-Form "/mantenimiento/equipos/$equipmentId/lecturas/$originalReadingId/corregir" @{
        kilometraje = '900'
        horometro = ''
        motivo = 'Corrección QA de lectura histórica'
        observaciones = 'Valor verificado contra registro externo'
    } $session $detail.Content
    $replacementId = Invoke-MySqlScalar "SELECT id FROM lecturas_equipo WHERE empresa_id=$companyId AND equipo_id=$equipmentId AND lectura_corregida_id=$originalReadingId;"
    $correctionState = Invoke-MySqlScalar "SELECT CONCAT((SELECT anulada FROM lecturas_equipo WHERE id=$originalReadingId),':',km_actual,':',sucursal_id) FROM equipos WHERE id=$equipmentId AND empresa_id=$companyId;"
    if ($replacementId -notmatch '^\d+$' -or $correctionState -ne "1:900:$destinationBranchId") {
        throw "La corrección histórica o el snapshot falló: reemplazo=$replacementId estado=$correctionState"
    }

    # Un token inválido debe bloquear la mutación en el borde HTTP.
    try {
        Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos/$equipmentId/editar" `
            -Method Post `
            -Body @{ codigo = 'NO-DEBE-CAMBIAR'; patente = ''; observaciones = ''; csrf_test_name = 'invalido' } `
            -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $session | Out-Null
        throw 'La mutación con CSRF inválido no fue bloqueada.'
    } catch {
        $response = $_.Exception.Response
        if ($null -eq $response -or [int] $response.StatusCode -ne 403) { throw }
    }
    if ((Invoke-MySqlScalar "SELECT codigo FROM equipos WHERE id=$equipmentId;") -ne $updatedCode) {
        throw 'La petición con CSRF inválido modificó el equipo.'
    }

    # El ID en la URL nunca debe alcanzar un equipo de otra empresa.
    Invoke-MySql "INSERT INTO empresas (razon_social,estado,created_at,updated_at) VALUES ('Empresa ajena $runId',1,NOW(),NOW());" | Out-Null
    $foreignCompanyId = Invoke-MySqlScalar "SELECT id FROM empresas WHERE razon_social='Empresa ajena $runId';"
    Invoke-MySql "INSERT INTO sucursales (empresa_id,codigo,nombre,estado,created_at,updated_at) VALUES ($foreignCompanyId,'EXT$runId','Sucursal ajena',1,NOW(),NOW());" | Out-Null
    $foreignBranchId = Invoke-MySqlScalar "SELECT id FROM sucursales WHERE empresa_id=$foreignCompanyId AND codigo='EXT$runId';"
    Invoke-MySql "INSERT INTO equipos (empresa_id,sucursal_id,tipo_equipo_id,codigo,estado,fecha_alta,created_at,updated_at) VALUES ($foreignCompanyId,$foreignBranchId,$equipmentTypeId,'FOREIGN-$runId','ACTIVO','2026-08-01',NOW(),NOW());" | Out-Null
    $foreignEquipmentId = Invoke-MySqlScalar "SELECT id FROM equipos WHERE empresa_id=$foreignCompanyId AND codigo='FOREIGN-$runId';"

    $detail = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos/$equipmentId" `
        -UseBasicParsing -WebSession $session
    Invoke-Form "/mantenimiento/equipos/$foreignEquipmentId/editar" @{
        codigo = 'INVASION-BLOQUEADA'
        patente = ''
        observaciones = 'No debe persistirse'
    } $session $detail.Content | Out-Null
    $foreignCode = Invoke-MySqlScalar "SELECT codigo FROM equipos WHERE id=$foreignEquipmentId AND empresa_id=$foreignCompanyId;"
    if ($foreignCode -ne "FOREIGN-$runId") {
        throw 'El actor modificó un equipo perteneciente a otra empresa.'
    }

    # Una OT abierta debe impedir la baja y conservar el equipo activo.
    Invoke-MySql "INSERT INTO ordenes_trabajo (numero,empresa_id,sucursal_id,equipo_id,origen,fecha_apertura,estado,created_at,updated_at) VALUES ('OT-QA-$runId',$companyId,$destinationBranchId,$equipmentId,'MANUAL',NOW(),'EMITIDA',NOW(),NOW());" | Out-Null
    $detail = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos/$equipmentId" `
        -UseBasicParsing -WebSession $session
    $blockedDecommission = Invoke-Form "/mantenimiento/equipos/$equipmentId/baja" @{
        fecha_baja = '2026-08-08'
    } $session $detail.Content
    $stateWithOpenOrder = Invoke-MySqlScalar "SELECT estado FROM equipos WHERE id=$equipmentId AND empresa_id=$companyId;"
    if ($stateWithOpenOrder -ne 'ACTIVO' -or $blockedDecommission.Content -notmatch 'rdenes de trabajo abiertas') {
        throw 'La baja no fue bloqueada frente a una orden de trabajo abierta.'
    }
    Invoke-MySql "UPDATE ordenes_trabajo SET estado='CANCELADA', motivo_cancelacion='Cierre controlado QA', updated_at=NOW() WHERE empresa_id=$companyId AND equipo_id=$equipmentId AND numero='OT-QA-$runId';" | Out-Null

    $readingsBeforeDecommission = Invoke-MySqlScalar "SELECT COUNT(*) FROM lecturas_equipo WHERE empresa_id=$companyId AND equipo_id=$equipmentId;"
    $detail = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos/$equipmentId" `
        -UseBasicParsing -WebSession $session
    $detail = Invoke-Form "/mantenimiento/equipos/$equipmentId/baja" @{
        fecha_baja = '2026-08-08'
    } $session $detail.Content
    $decommissionState = Invoke-MySqlScalar "SELECT CONCAT(estado,':',fecha_baja) FROM equipos WHERE id=$equipmentId AND empresa_id=$companyId;"
    if ($decommissionState -ne 'BAJA:2026-08-08') { throw "La baja lógica falló: $decommissionState" }

    $detail = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos/$equipmentId" `
        -UseBasicParsing -WebSession $session
    $detail = Invoke-Form "/mantenimiento/equipos/$equipmentId/lecturas" @{
        fecha_lectura = '2026-08-09 10:00:00'
        kilometraje = '1100'
        horometro = ''
        motivo_correccion = ''
        observaciones = 'No debe persistirse'
    } $session $detail.Content
    $readingsAfterRejectedWrite = Invoke-MySqlScalar "SELECT COUNT(*) FROM lecturas_equipo WHERE empresa_id=$companyId AND equipo_id=$equipmentId;"
    if ($readingsAfterRejectedWrite -ne $readingsBeforeDecommission -or $detail.Content -notmatch 'baja') {
        throw 'Un equipo dado de baja aceptó una lectura o no informó el rechazo.'
    }

    Write-Host "FASE 2B E2E PASS: equipo=$equipmentId, edicion=ok, no_op=ok, traslado=$transferState, lectura_original=$originalReadingId, reemplazo=$replacementId, baja_con_ot=bloqueada, baja=$decommissionState, csrf=403, tenant_ajeno=bloqueado, historial_conservado=ok" -ForegroundColor Green
}
finally {
    if ($server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force
    }
    foreach ($key in $dottedEnvironmentKeys) {
        if ($savedDottedEnvironment.ContainsKey($key)) {
            [Environment]::SetEnvironmentVariable($key, $savedDottedEnvironment[$key], 'Process')
        }
    }
    if ($databaseCreated -and $databaseName -match '^mantenimiento_fase2b_[a-f0-9]{8}$') {
        Invoke-MySql "DROP DATABASE $databaseName;" -WithoutDatabase | Out-Null
    }
    if (Test-Path -LiteralPath $temporaryRoot) {
        $resolvedTemporaryRoot = (Resolve-Path -LiteralPath $temporaryRoot).Path
        if ($resolvedTemporaryRoot.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
            -and (Split-Path $resolvedTemporaryRoot -Leaf) -match '^mantenimiento-fase2b-[a-f0-9]{8}$') {
            Remove-Item -LiteralPath $resolvedTemporaryRoot -Recurse -Force
        }
    }
}
