[CmdletBinding()]
param(
    [int]$HttpPort = 8084,
    [string]$MySqlExecutable = 'C:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runId = [guid]::NewGuid().ToString('N').Substring(0, 8)
$databaseName = "mantenimiento_tenant_$runId"
$temporaryBase = [System.IO.Path]::GetTempPath().TrimEnd('\')
$temporaryRoot = Join-Path $temporaryBase "mantenimiento-tenant-$runId"
$server = $null
$databaseCreated = $false

if ($databaseName -notmatch '^mantenimiento_tenant_[a-f0-9]{8}$') {
    throw "Nombre de base temporal inseguro: $databaseName"
}
if (-not $temporaryRoot.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
    -or (Split-Path $temporaryRoot -Leaf) -notmatch '^mantenimiento-tenant-[a-f0-9]{8}$') {
    throw "Ruta temporal insegura: $temporaryRoot"
}

function Invoke-MySql {
    param([Parameter(Mandatory)] [string]$Sql, [switch]$WithoutDatabase)

    $arguments = @('--protocol=tcp', '-h', '127.0.0.1', '-P', '3306', '-u', 'root', '-N')
    if (-not $WithoutDatabase) { $arguments += @('-D', $databaseName) }
    $arguments += @('-e', $Sql)
    $output = & $MySqlExecutable @arguments
    if ($LASTEXITCODE -ne 0) { throw 'Falló la operación MySQL.' }
    return $output
}

function Get-CsrfToken {
    param([Parameter(Mandatory)] [string]$Html)

    $match = [regex]::Match($Html, 'name="csrf_test_name"\s+value="([^"]+)"')
    if (-not $match.Success) { throw 'No se encontró el token CSRF.' }
    return $match.Groups[1].Value
}

function New-AuthenticatedSession {
    param([Parameter(Mandatory)] [string]$Email, [Parameter(Mandatory)] [string]$Password)

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest "http://127.0.0.1:$HttpPort/login" -UseBasicParsing -WebSession $session
    Invoke-WebRequest "http://127.0.0.1:$HttpPort/login/authenticate" `
        -Method Post `
        -Body @{ email = $Email; password = $Password; csrf_test_name = Get-CsrfToken $login.Content } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $session | Out-Null
    return $session
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
    if ($LASTEXITCODE -ge 8) { throw "Robocopy finalizó con código $LASTEXITCODE." }

    foreach ($directory in @('cache', 'logs', 'session', 'uploads', 'debugbar')) {
        $runtimePath = Join-Path $temporaryRoot "writable\$directory"
        New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null
        Copy-Item -LiteralPath (Join-Path $source "writable\$directory\index.html") -Destination (Join-Path $runtimePath 'index.html')
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
    } finally { Pop-Location }

    Invoke-MySql "INSERT INTO empresas (id,razon_social,estado,created_at,updated_at) VALUES (2,'Empresa Ajena SA',1,NOW(),NOW()); INSERT INTO sucursales (id,empresa_id,codigo,nombre,estado,created_at,updated_at) VALUES (2,2,'AJENA','Sucursal Ajena',1,NOW(),NOW()); INSERT INTO usuarios (id,empresa_id,nombre,email,password_hash,es_superadmin,activo,created_at,updated_at) VALUES (3,2,'Usuario Ajeno','ajeno@example.test','no-utilizada',0,1,NOW(),NOW());" | Out-Null

    $server = Start-Process php -ArgumentList @('-S', "127.0.0.1:$HttpPort", 'index.php') -WorkingDirectory $temporaryRoot -WindowStyle Hidden -PassThru
    $deadline = (Get-Date).AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 250
        $listener = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue
    } until ($listener -or (Get-Date) -ge $deadline)
    if (-not $listener) { throw 'El servidor HTTP temporal no inició.' }

    $administrator = New-AuthenticatedSession 'admin@mantenimiento.local' 'Admin1234'
    $branchesPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales" -UseBasicParsing -WebSession $administrator
    if ($branchesPage.StatusCode -ne 200 -or $branchesPage.Content -notmatch 'Empresa Demo S.A.' -or $branchesPage.Content -match 'Sucursal Ajena') {
        throw 'El listado de sucursales no respetó el scope de empresa.'
    }
    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios" -UseBasicParsing -WebSession $administrator
    if ($usersPage.StatusCode -ne 200 -or $usersPage.Content -match 'ajeno@example.test') {
        throw 'El listado de usuarios expuso otra empresa.'
    }

    $branchesPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $branchesPage.Content; codigo = 'NORTE'; nombre = 'Base Norte'; direccion = 'Ruta 1'; email_alertas = 'norte@example.test' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    $branchId = (Invoke-MySql "SELECT id FROM sucursales WHERE empresa_id=1 AND codigo='NORTE';").Trim()
    if ($branchId -notmatch '^\d+$') { throw 'No se creó la sucursal de la empresa propia.' }

    $branchesPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales/2" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $branchesPage.Content; codigo = 'AJENA'; nombre = 'Alterada'; direccion = ''; email_alertas = ''; estado = '1' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    if ($branchesPage.Content -notmatch 'no existe dentro de tu empresa' -or (Invoke-MySql 'SELECT nombre FROM sucursales WHERE id=2;').Trim() -ne 'Sucursal Ajena') {
        throw 'Fue posible editar una sucursal de otra empresa.'
    }

    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios" -UseBasicParsing -WebSession $administrator
    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $usersPage.Content; nombre = 'Usuario Restringido'; email = 'restringido@example.test'; password = 'Inicial123'; password_confirmation = 'Inicial123'; 'roles[]' = '5'; 'sucursales[]' = $branchId; motivo = 'Alta E2E aprobada' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    $userId = (Invoke-MySql "SELECT id FROM usuarios WHERE empresa_id=1 AND email='restringido@example.test';").Trim()
    $createdState = (Invoke-MySql "SELECT CONCAT((SELECT empresa_id FROM usuarios WHERE id=$userId),':',(SELECT COUNT(*) FROM usuario_roles WHERE usuario_id=$userId AND rol_id=5),':',(SELECT COUNT(*) FROM usuario_sucursales WHERE usuario_id=$userId AND sucursal_id=$branchId),':',(SELECT COUNT(*) FROM usuario_acceso_historial WHERE usuario_id=$userId AND actor_usuario_id=1 AND accion='USUARIO_CREADO'));").Trim()
    if ($createdState -ne '1:1:1:1') { throw "El alta de usuario no fue consistente: $createdState" }

    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios/3" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $usersPage.Content; nombre = 'Alterado'; email = 'ajeno@example.test'; activo = '1'; motivo = 'Intento ajeno' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    if ($usersPage.Content -notmatch 'no existe dentro de tu empresa' -or (Invoke-MySql 'SELECT nombre FROM usuarios WHERE id=3;').Trim() -ne 'Usuario Ajeno') {
        throw 'Fue posible editar un usuario de otra empresa.'
    }

    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios/$userId/acceso" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $usersPage.Content; 'roles[]' = '5'; 'sucursales[]' = '2'; motivo = 'Intento sucursal ajena' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    $accessAfterAttack = (Invoke-MySql "SELECT GROUP_CONCAT(sucursal_id ORDER BY sucursal_id) FROM usuario_sucursales WHERE usuario_id=$userId;").Trim()
    if ($usersPage.Content -notmatch 'no pertenecen a tu empresa' -or $accessAfterAttack -ne $branchId) {
        throw 'La asignación ajena no hizo rollback completo.'
    }

    $branchesPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales" -UseBasicParsing -WebSession $administrator
    $branchesPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales/$branchId" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $branchesPage.Content; codigo = 'NORTE'; nombre = 'Base Norte'; direccion = 'Ruta 1'; email_alertas = 'norte@example.test'; estado = '0' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    $blockedBranchState = (Invoke-MySql "SELECT estado FROM sucursales WHERE id=$branchId;").Trim()
    if ($blockedBranchState -ne '1') { throw "Se inactivó una sucursal indispensable para un usuario: estado=$blockedBranchState" }
    if ($branchesPage.Content -notmatch 'sin ninguna sucursal') { throw 'No se informó por qué la sucursal indispensable quedó activa.' }

    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios" -UseBasicParsing -WebSession $administrator
    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios/$userId/acceso" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $usersPage.Content; 'roles[]' = '5'; 'sucursales[]' = '1'; motivo = 'Cambio a central' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    $branchesPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales" -UseBasicParsing -WebSession $administrator
    $branchesPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/sucursales/$branchId" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $branchesPage.Content; codigo = 'NORTE'; nombre = 'Base Norte'; direccion = 'Ruta 1'; email_alertas = 'norte@example.test'; estado = '0' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    if ((Invoke-MySql "SELECT estado FROM sucursales WHERE id=$branchId;").Trim() -ne '0') { throw 'No se pudo inactivar una sucursal ya liberada.' }

    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios" -UseBasicParsing -WebSession $administrator
    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios/$userId/password" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $usersPage.Content; password = 'NuevaClave123'; password_confirmation = 'NuevaClave123'; motivo = 'Reset E2E aprobado' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    $restricted = New-AuthenticatedSession 'restringido@example.test' 'NuevaClave123'
    $restrictedDashboard = Invoke-WebRequest "http://127.0.0.1:$HttpPort/dashboard" -UseBasicParsing -WebSession $restricted
    if ($restrictedDashboard.Content -notmatch 'Usuario Restringido') { throw 'La contraseña restablecida no permitió autenticar.' }
    try {
        Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios" -UseBasicParsing -WebSession $restricted | Out-Null
        throw 'Un usuario sin permiso abrió la administración.'
    } catch {
        if ($null -eq $_.Exception.Response -or [int] $_.Exception.Response.StatusCode -ne 403) { throw }
    }

    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios" -UseBasicParsing -WebSession $administrator
    $usersPage = Invoke-WebRequest "http://127.0.0.1:$HttpPort/administracion/usuarios/$userId" `
        -Method Post `
        -Body @{ csrf_test_name = Get-CsrfToken $usersPage.Content; nombre = 'Usuario Restringido'; email = 'restringido@example.test'; activo = '0'; motivo = 'Baja E2E aprobada' } `
        -ContentType 'application/x-www-form-urlencoded' -UseBasicParsing -WebSession $administrator
    $afterDeactivation = Invoke-WebRequest "http://127.0.0.1:$HttpPort/dashboard" -UseBasicParsing -WebSession $restricted
    if ($afterDeactivation.BaseResponse.ResponseUri.AbsolutePath -ne '/login') { throw 'La sesión del usuario inactivo siguió autorizada.' }

    $auditCount = (Invoke-MySql "SELECT COUNT(*) FROM usuario_acceso_historial WHERE usuario_id=$userId AND actor_usuario_id=1;").Trim()
    if ([int] $auditCount -lt 4) { throw "Faltó trazabilidad de operaciones: $auditCount" }

    Write-Host "TENANT ADMIN E2E PASS: aislamiento=ok, sucursal=$branchId, usuario=$userId, acceso_atomico=ok, inactivacion_segura=ok, sesion_revocada=ok, auditorias=$auditCount" -ForegroundColor Green
}
finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    if ($databaseCreated -and $databaseName -match '^mantenimiento_tenant_[a-f0-9]{8}$') {
        Invoke-MySql "DROP DATABASE $databaseName;" -WithoutDatabase | Out-Null
    }
    if (Test-Path -LiteralPath $temporaryRoot) {
        $resolved = (Resolve-Path -LiteralPath $temporaryRoot).Path
        if ($resolved.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
            -and (Split-Path $resolved -Leaf) -match '^mantenimiento-tenant-[a-f0-9]{8}$') {
            Remove-Item -LiteralPath $resolved -Recurse -Force
        }
    }
}
