[CmdletBinding()]
param(
    [int]$HttpPort = 8082,
    [string]$MySqlExecutable = 'C:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runId = [guid]::NewGuid().ToString('N').Substring(0, 8)
$databaseName = "mantenimiento_org_$runId"
$temporaryBase = [System.IO.Path]::GetTempPath().TrimEnd('\')
$temporaryRoot = Join-Path $temporaryBase "mantenimiento-org-$runId"
$server = $null
$databaseCreated = $false

if ($databaseName -notmatch '^mantenimiento_org_[a-f0-9]{8}$') {
    throw "Nombre de base temporal inseguro: $databaseName"
}
if (-not $temporaryRoot.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
    -or (Split-Path $temporaryRoot -Leaf) -notmatch '^mantenimiento-org-[a-f0-9]{8}$') {
    throw "Ruta temporal insegura: $temporaryRoot"
}
if (-not (Test-Path -LiteralPath $MySqlExecutable)) {
    throw "No se encontró MySQL: $MySqlExecutable"
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
        throw 'Falló la operación MySQL.'
    }
    return $output
}

function Get-CsrfToken {
    param([Parameter(Mandatory)] [string]$Html)

    $match = [regex]::Match($Html, 'name="csrf_test_name"\s+value="([^"]+)"')
    if ($match.Success) {
        return $match.Groups[1].Value
    }

    $payloadMatch = [regex]::Match(
        $Html,
        '<script id="maintenance-app-data" type="application/json">(?<json>.*?)</script>',
        [System.Text.RegularExpressions.RegexOptions]::Singleline
    )
    if ($payloadMatch.Success) {
        $payload = $payloadMatch.Groups['json'].Value | ConvertFrom-Json
        if ($payload.data.csrf.name -eq 'csrf_test_name' -and $payload.data.csrf.hash) {
            return [string] $payload.data.csrf.hash
        }
    }

    if (-not $match.Success) {
        throw 'No se encontró el token CSRF.'
    }
    return $match.Groups[1].Value
}

function New-AuthenticatedSession {
    param([Parameter(Mandatory)] [string]$Email, [Parameter(Mandatory)] [string]$Password)

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest "http://127.0.0.1:$HttpPort/login" -UseBasicParsing -WebSession $session
    $token = Get-CsrfToken $login.Content
    Invoke-WebRequest "http://127.0.0.1:$HttpPort/login/authenticate" `
        -Method Post `
        -Body @{ email = $Email; password = $Password; csrf_test_name = $token } `
        -ContentType 'application/x-www-form-urlencoded' `
        -UseBasicParsing `
        -WebSession $session | Out-Null
    return $session
}

function Assert-LoginRateLimit {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/login" -UseBasicParsing -WebSession $session

    for ($attempt = 1; $attempt -le 6; $attempt++) {
        $token = Get-CsrfToken $page.Content

        try {
            $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/login/authenticate" `
                -Method Post `
                -Body @{ email = 'rate-limit@example.test'; password = 'incorrecta'; csrf_test_name = $token } `
                -ContentType 'application/x-www-form-urlencoded' `
                -UseBasicParsing `
                -WebSession $session

            if ($attempt -eq 6) {
                throw 'El sexto intento de login no fue bloqueado.'
            }
        } catch {
            $response = $_.Exception.Response
            if ($null -eq $response -or [int] $response.StatusCode -ne 429 -or $attempt -ne 6) {
                throw
            }

            $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
            try {
                $body = $reader.ReadToEnd()
            } finally {
                $reader.Dispose()
            }

            if ($body -notmatch 'Demasiados intentos' -or [int] $response.Headers['Retry-After'] -lt 1) {
                throw 'El bloqueo de login no informó mensaje o Retry-After.'
            }
        }
    }
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
        Copy-Item -LiteralPath (Join-Path $source "writable\$directory\index.html") `
            -Destination (Join-Path $runtimePath 'index.html')
    }

    Invoke-MySql "CREATE DATABASE $databaseName CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" -WithoutDatabase | Out-Null
    $databaseCreated = $true

    [Environment]::SetEnvironmentVariable('CI_ENVIRONMENT', 'development', 'Process')
    [Environment]::SetEnvironmentVariable('app.baseURL', "http://127.0.0.1:$HttpPort/", 'Process')
    [Environment]::SetEnvironmentVariable('database.default.hostname', '127.0.0.1', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.database', $databaseName, 'Process')
    [Environment]::SetEnvironmentVariable('database.default.username', 'root', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.password', '', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.DBDriver', 'MySQLi', 'Process')
    [Environment]::SetEnvironmentVariable('database.default.port', '3306', 'Process')

    Push-Location $temporaryRoot
    try {
        & php spark migrate --all
        if ($LASTEXITCODE -ne 0) { throw 'Fallaron las migraciones.' }
        & php spark db:seed InitialSeeder
        if ($LASTEXITCODE -ne 0) { throw 'Falló el seeder.' }
    } finally {
        Pop-Location
    }

    $server = Start-Process php -ArgumentList @('-S', "127.0.0.1:$HttpPort", 'index.php') `
        -WorkingDirectory $temporaryRoot -WindowStyle Hidden -PassThru
    $deadline = (Get-Date).AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 250
        $listener = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue
    } until ($listener -or (Get-Date) -ge $deadline)
    if (-not $listener) { throw 'El servidor HTTP temporal no inició.' }

    Assert-LoginRateLimit
    $superAdmin = New-AuthenticatedSession 'superadmin@mantenimiento.local' 'SuperAdmin1234'
    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin" -UseBasicParsing -WebSession $superAdmin
    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/empresas" `
        -Method Post `
        -Body @{
            csrf_test_name = Get-CsrfToken $page.Content
            razon_social = 'Empresa Temporal SA'
            nombre_fantasia = 'Temporal'
            cuit = "30-$runId"
            email = 'temporal@example.test'
            telefono = '123'
        } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    if ($page.Content -notmatch 'Empresa Temporal SA') { throw 'La empresa creada no aparece.' }

    $companyId = (Invoke-MySql "SELECT id FROM empresas WHERE razon_social='Empresa Temporal SA';" | Select-Object -First 1).Trim()
    if ($companyId -notmatch '^\d+$') { throw 'No se obtuvo la empresa temporal.' }

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/empresas/$companyId" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $page.Content; razon_social = 'Empresa Temporal Actualizada SA'; nombre_fantasia = 'Temporal'; cuit = "30-$runId"; email = 'temporal@example.test'; telefono = '456'; estado = '1' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    $updatedCompany = (Invoke-MySql "SELECT CONCAT(razon_social,':',telefono,':',estado) FROM empresas WHERE id=$companyId;").Trim()
    if ($updatedCompany -ne 'Empresa Temporal Actualizada SA:456:1') { throw "La edicion de empresa fallo: $updatedCompany" }

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/administradores" `
        -Method Post `
        -Body @{
            csrf_test_name = Get-CsrfToken $page.Content
            admin_empresa_id = $companyId
            admin_nombre = 'Administradora Nueva'
            admin_email = 'administradora.nueva@example.test'
            admin_password = 'ClaveTemporal123'
            admin_password_confirmation = 'ClaveTemporal123'
            admin_motivo = 'Alta inicial E2E aprobada'
        } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    $newAdministratorId = (Invoke-MySql "SELECT id FROM usuarios WHERE email='administradora.nueva@example.test';" | Select-Object -First 1).Trim()
    if ($newAdministratorId -notmatch '^\d+$') { throw 'No se creÃ³ el administrador empresarial.' }
    $administratorState = (Invoke-MySql "SELECT CONCAT(empresa_id,':',activo,':',es_superadmin,':',(SELECT COUNT(*) FROM usuario_roles WHERE usuario_id=$newAdministratorId AND rol_id=(SELECT id FROM roles WHERE nombre='Administrador')),':',(SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id=$newAdministratorId),':',(SELECT COUNT(*) FROM usuario_acceso_historial WHERE usuario_id=$newAdministratorId AND accion='USUARIO_CREADO')) FROM usuarios WHERE id=$newAdministratorId;").Trim()
    if ($administratorState -ne "$companyId`:1:0:1:0:1") { throw "El alta atÃ³mica del administrador fallÃ³: $administratorState" }

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/administradores" `
        -Method Post `
        -Body @{
            csrf_test_name = Get-CsrfToken $page.Content
            admin_empresa_id = $companyId
            admin_nombre = 'Administradora Duplicada'
            admin_email = 'administradora.nueva@example.test'
            admin_password = 'OtraClaveTemporal123'
            admin_password_confirmation = 'OtraClaveTemporal123'
            admin_motivo = 'Duplicado E2E controlado'
        } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    $duplicateState = (Invoke-MySql "SELECT CONCAT((SELECT COUNT(*) FROM usuarios WHERE email='administradora.nueva@example.test'),':',(SELECT COUNT(*) FROM usuario_roles WHERE usuario_id=$newAdministratorId),':',(SELECT COUNT(*) FROM usuario_acceso_historial WHERE usuario_id=$newAdministratorId));").Trim()
    if ($duplicateState -ne '1:1:1' -or $page.Content -notmatch 'Ya existe un usuario') { throw "El duplicado no fue rechazado sin efectos parciales: $duplicateState" }

    Invoke-MySql "INSERT INTO sucursales (empresa_id,codigo,nombre,estado,created_at,updated_at) VALUES ($companyId,'NUEVA','Sucursal Nueva',1,NOW(),NOW());" | Out-Null

    $newAdministrator = New-AuthenticatedSession 'administradora.nueva@example.test' 'ClaveTemporal123'
    $newAdministratorBranches = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales" -UseBasicParsing -WebSession $newAdministrator
    if ($newAdministratorBranches.Content -notmatch '"company":\{"id":[0-9]+,"name":"Temporal"\}' `
        -or $newAdministratorBranches.Content -notmatch 'Sucursal Nueva') {
        $pageMatch = [regex]::Match($newAdministratorBranches.Content, '"page":"([^"]+)"')
        $detectedPage = if ($pageMatch.Success) { $pageMatch.Groups[1].Value } else { 'sin-payload' }
        throw "El nuevo Administrador no heredÃ³ el alcance completo de su empresa: page=$detectedPage company=$($newAdministratorBranches.Content -match '"name":"Temporal"') branch=$($newAdministratorBranches.Content -match 'Sucursal Nueva')."
    }

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/usuarios/1/empresa" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $page.Content; empresa_id = $companyId; motivo = 'Traslado E2E aprobado' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    $state = (Invoke-MySql "SELECT CONCAT(empresa_id,':',(SELECT COUNT(*) FROM usuario_roles WHERE usuario_id=1),':',(SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id=1),':',(SELECT COUNT(*) FROM usuario_acceso_historial WHERE usuario_id=1 AND accion='CAMBIO_EMPRESA')) FROM usuarios WHERE id=1;").Trim()
    if ($state -ne "$companyId`:0:0:1") { throw "El traslado no limpió accesos: $state" }

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/usuarios/1/roles" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $page.Content; 'roles[]' = '1'; motivo = 'Rol administrador aprobado' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    $roleState = (Invoke-MySql "SELECT CONCAT((SELECT COUNT(*) FROM usuario_roles WHERE usuario_id=1 AND rol_id=1),':',(SELECT COUNT(*) FROM usuario_acceso_historial WHERE usuario_id=1 AND accion='ASIGNACION_ROLES'));").Trim()
    if ($roleState -ne '1:1') { throw "La asignación de roles falló: $roleState" }

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/empresas/$companyId" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $page.Content; razon_social = 'Empresa Temporal Actualizada SA'; nombre_fantasia = 'Temporal'; cuit = "30-$runId"; email = 'temporal@example.test'; telefono = '456'; estado = '0' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    if ($page.Content -notmatch 'No se puede inactivar') { throw 'No se informo el bloqueo de inactivacion con usuarios activos.' }
    $companyState = (Invoke-MySql "SELECT estado FROM empresas WHERE id=$companyId;").Trim()
    if ($companyState -ne '1') { throw 'Se inactivo una empresa con usuarios activos.' }

    $administrator = New-AuthenticatedSession 'admin@mantenimiento.local' 'Admin1234'
    $administratorBranches = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales" -UseBasicParsing -WebSession $administrator
    if ($administratorBranches.Content -notmatch '"company":\{"id":[0-9]+,"name":"Temporal"\}' `
        -or $administratorBranches.Content -notmatch 'Sucursal Nueva') {
        throw 'El Administrador no heredó todas las sucursales de su nueva empresa.'
    }

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort/superadmin/usuarios/2/empresa" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $page.Content; empresa_id = $companyId; motivo = 'Intento inválido controlado' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $superAdmin
    $superAdminCompany = (Invoke-MySql "SELECT COALESCE(CAST(empresa_id AS CHAR),'NULL') FROM usuarios WHERE id=2;").Trim()
    if ($superAdminCompany -ne 'NULL') { throw 'Se asignó empresa al Superadministrador.' }

    Write-Host "ORGANIZATION E2E PASS: login_limitado=ok, empresa=$companyId, edicion=ok, nuevo_admin=$newAdministratorId, alta_atomica=$administratorState, duplicado=$duplicateState, limpieza=$state, roles=$roleState, inactivacion_bloqueada=ok, sucursales_admin=1" -ForegroundColor Green
}
finally {
    if ($server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force
    }
    if ($databaseCreated -and $databaseName -match '^mantenimiento_org_[a-f0-9]{8}$') {
        Invoke-MySql "DROP DATABASE $databaseName;" -WithoutDatabase | Out-Null
    }
    if (Test-Path -LiteralPath $temporaryRoot) {
        $resolvedTemporaryRoot = (Resolve-Path -LiteralPath $temporaryRoot).Path
        if ($resolvedTemporaryRoot.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
            -and (Split-Path $resolvedTemporaryRoot -Leaf) -match '^mantenimiento-org-[a-f0-9]{8}$') {
            Remove-Item -LiteralPath $resolvedTemporaryRoot -Recurse -Force
        }
    }
}
