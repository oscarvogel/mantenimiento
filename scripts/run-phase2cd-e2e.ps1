[CmdletBinding()]
param(
    [int]$HttpPort = 8085,
    [string]$MySqlExecutable = 'C:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http

$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runId = [guid]::NewGuid().ToString('N').Substring(0, 8)
$databaseName = "mantenimiento_fase2cd_$runId"
$temporaryBase = [System.IO.Path]::GetTempPath().TrimEnd('\')
$temporaryRoot = Join-Path $temporaryBase "mantenimiento-fase2cd-$runId"
$privateRoot = Join-Path $temporaryBase "mantenimiento-fase2cd-private-$runId"
$attachmentRoot = Join-Path $privateRoot 'adjuntos'
$importRoot = Join-Path $privateRoot 'importaciones'
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
    'database.default.port',
    'uploads.privatePath',
    'uploads.maxSizeMB',
    'imports.privatePath',
    'imports.maxSizeMB'
)

if ($databaseName -notmatch '^mantenimiento_fase2cd_[a-f0-9]{8}$') {
    throw "Nombre de base temporal inseguro: $databaseName"
}
foreach ($path in @($temporaryRoot, $privateRoot)) {
    if (-not $path.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
        -or (Split-Path $path -Leaf) -notmatch '^mantenimiento-fase2cd(-private)?-[a-f0-9]{8}$') {
        throw "Ruta temporal insegura: $path"
    }
}
if (-not (Test-Path -LiteralPath $MySqlExecutable)) {
    throw "No se encontro MySQL: $MySqlExecutable"
}
if (Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue) {
    throw "El puerto HTTP $HttpPort ya esta ocupado. Elegir otro con -HttpPort."
}

function Invoke-MySql {
    param([Parameter(Mandatory)] [string]$Sql, [switch]$WithoutDatabase)

    # --raw evita que el cliente duplique barras de rutas Windows en su salida.
    $arguments = @('--protocol=tcp', '-h', '127.0.0.1', '-P', '3306', '-u', 'root', '-N', '--raw')
    if (-not $WithoutDatabase) {
        $arguments += @('-D', $databaseName)
    }
    $arguments += @('-e', $Sql)
    $output = & $MySqlExecutable @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Fallo la operacion sobre la base MariaDB temporal.'
    }

    return $output
}

function Invoke-MySqlScalar {
    param([Parameter(Mandatory)] [string]$Sql)

    $value = Invoke-MySql $Sql | Select-Object -First 1
    if ($null -eq $value) {
        return ''
    }

    return ([string] $value).Trim()
}

function Assert-Equal {
    param(
        [Parameter(Mandatory)] [string]$Expected,
        [AllowEmptyString()] [string]$Actual,
        [Parameter(Mandatory)] [string]$Message
    )

    if ($Actual -ne $Expected) {
        throw "$Message Esperado='$Expected', obtenido='$Actual'."
    }
}

function Get-CsrfToken {
    param([Parameter(Mandatory)] [string]$Html)

    $match = [regex]::Match($Html, 'name="csrf_test_name"\s+value="([^"]+)"')
    if (-not $match.Success) {
        throw 'No se encontro el token CSRF en la respuesta.'
    }

    return $match.Groups[1].Value
}

function New-AuthenticatedSession {
    param(
        [Parameter(Mandatory)] [string]$Email,
        [Parameter(Mandatory)] [string]$Password
    )

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest "http://127.0.0.1:$HttpPort/login" `
        -UseBasicParsing -WebSession $session
    Invoke-WebRequest "http://127.0.0.1:$HttpPort/login/authenticate" `
        -Method Post `
        -Body @{
            email = $Email
            password = $Password
            csrf_test_name = Get-CsrfToken $login.Content
        } `
        -ContentType 'application/x-www-form-urlencoded' `
        -UseBasicParsing -WebSession $session | Out-Null

    $dashboard = Invoke-WebRequest "http://127.0.0.1:$HttpPort/dashboard" `
        -UseBasicParsing -WebSession $session
    if ($dashboard.BaseResponse.ResponseUri.AbsolutePath -ne '/dashboard') {
        throw "No se pudo autenticar $Email en la instalacion temporal."
    }

    return $session
}

function Invoke-Form {
    param(
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [hashtable]$Fields,
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$TokenPath = $Path
    )

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort$TokenPath" `
        -UseBasicParsing -WebSession $Session
    $body = @{}
    foreach ($entry in $Fields.GetEnumerator()) {
        $body[$entry.Key] = $entry.Value
    }
    $body['csrf_test_name'] = Get-CsrfToken $page.Content

    return Invoke-WebRequest "http://127.0.0.1:$HttpPort$Path" `
        -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' `
        -UseBasicParsing -WebSession $Session
}

function Invoke-Multipart {
    param(
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [hashtable]$Fields,
        [Parameter(Mandatory)] [string]$FileField,
        [Parameter(Mandatory)] [string]$FilePath,
        [Parameter(Mandatory)] [string]$FileName,
        [Parameter(Mandatory)] [string]$MediaType,
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [string]$TokenPath = $Path,
        [bool]$FollowRedirect = $true
    )

    $page = Invoke-WebRequest "http://127.0.0.1:$HttpPort$TokenPath" `
        -UseBasicParsing -WebSession $Session
    $handler = New-Object System.Net.Http.HttpClientHandler
    $handler.CookieContainer = $Session.Cookies
    $handler.AllowAutoRedirect = $FollowRedirect
    $client = New-Object System.Net.Http.HttpClient($handler)
    $multipart = New-Object System.Net.Http.MultipartFormDataContent
    try {
        $multipart.Add((New-Object System.Net.Http.StringContent((Get-CsrfToken $page.Content))), 'csrf_test_name')
        foreach ($entry in $Fields.GetEnumerator()) {
            $multipart.Add((New-Object System.Net.Http.StringContent(([string] $entry.Value))), $entry.Key)
        }
        $fileContent = New-Object System.Net.Http.ByteArrayContent(,[System.IO.File]::ReadAllBytes($FilePath))
        $fileContent.Headers.ContentType = New-Object System.Net.Http.Headers.MediaTypeHeaderValue($MediaType)
        $multipart.Add($fileContent, $FileField, $FileName)

        $response = $client.PostAsync("http://127.0.0.1:$HttpPort$Path", $multipart).GetAwaiter().GetResult()
        $bytes = $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
        return [pscustomobject]@{
            StatusCode = [int] $response.StatusCode
            Body = [System.Text.Encoding]::UTF8.GetString($bytes)
            Bytes = $bytes
            ContentType = if ($null -eq $response.Content.Headers.ContentType) { '' } else { $response.Content.Headers.ContentType.MediaType }
        }
    } finally {
        $multipart.Dispose()
        $client.Dispose()
        $handler.Dispose()
    }
}

function Invoke-BinaryGet {
    param(
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )

    $handler = New-Object System.Net.Http.HttpClientHandler
    $handler.CookieContainer = $Session.Cookies
    $handler.AllowAutoRedirect = $true
    $client = New-Object System.Net.Http.HttpClient($handler)
    try {
        $response = $client.GetAsync("http://127.0.0.1:$HttpPort$Path").GetAwaiter().GetResult()
        return [pscustomobject]@{
            StatusCode = [int] $response.StatusCode
            Bytes = $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
            ContentType = if ($null -eq $response.Content.Headers.ContentType) { '' } else { $response.Content.Headers.ContentType.MediaType }
            FinalPath = $response.RequestMessage.RequestUri.AbsolutePath
        }
    } finally {
        $client.Dispose()
        $handler.Dispose()
    }
}

function Write-Utf8File {
    param([Parameter(Mandatory)] [string]$Path, [Parameter(Mandatory)] [string]$Content)
    [System.IO.File]::WriteAllText($Path, $Content, [System.Text.UTF8Encoding]::new($false))
}

function Get-Sha256Hex {
    param([Parameter(Mandatory)] [byte[]]$Bytes)

    $algorithm = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($algorithm.ComputeHash($Bytes))).Replace('-', '')
    } finally {
        $algorithm.Dispose()
    }
}

function Invoke-Spark {
    param([Parameter(Mandatory)] [string[]]$Arguments, [Parameter(Mandatory)] [string]$Failure)

    Push-Location $temporaryRoot
    try {
        & php spark @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw $Failure
        }
    } finally {
        Pop-Location
    }
}

try {
    New-Item -ItemType Directory -Path $temporaryRoot -Force | Out-Null
    New-Item -ItemType Directory -Path $privateRoot -Force | Out-Null
    & robocopy.exe $source $temporaryRoot /E `
        /XD (Join-Path $source '.git') `
            (Join-Path $source 'writable\cache') `
            (Join-Path $source 'writable\logs') `
            (Join-Path $source 'writable\session') `
            (Join-Path $source 'writable\uploads') `
            (Join-Path $source 'writable\debugbar') `
        /XF '.env' /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) {
        throw "Robocopy finalizo con codigo $LASTEXITCODE."
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

    foreach ($key in $dottedEnvironmentKeys) {
        $savedDottedEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $null, 'Process')
    }

    $envAttachmentRoot = $attachmentRoot.Replace('\', '/')
    $envImportRoot = $importRoot.Replace('\', '/')
    $temporaryEnvironment = @"
CI_ENVIRONMENT = development
app.baseURL = 'http://127.0.0.1:$HttpPort/'
database.default.hostname = 127.0.0.1
database.default.database = $databaseName
database.default.username = root
database.default.password = ''
database.default.DBDriver = MySQLi
database.default.port = 3306
uploads.privatePath = '$envAttachmentRoot'
uploads.maxSizeMB = 10
imports.privatePath = '$envImportRoot'
imports.maxSizeMB = 10
"@
    Write-Utf8File (Join-Path $temporaryRoot '.env') $temporaryEnvironment

    # Prueba real de up/down de todo el batch, incluida 110084, siempre en la DB temporal.
    Invoke-Spark @('migrate', '--all') 'Fallo la primera subida de migraciones en la base temporal.'
    Assert-Equal '2' (Invoke-MySqlScalar "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='$databaseName' AND table_name='sucursales' AND index_name='uq_sucursales_empresa_id';") 'No se creo el indice tenant compuesto de sucursales.'
    Invoke-Spark @('migrate:rollback', '-f') 'Fallo el rollback de migraciones en la base temporal.'
    Assert-Equal '0' (Invoke-MySqlScalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$databaseName' AND table_name='sucursales';") 'El rollback no retiro el esquema funcional.'
    Invoke-Spark @('migrate', '--all') 'Fallo la segunda subida limpia de migraciones en la base temporal.'
    Invoke-Spark @('db:seed', 'InitialSeeder') 'Fallo InitialSeeder en la base temporal.'

    Assert-Equal '2' (Invoke-MySqlScalar "SELECT COUNT(*) FROM permisos WHERE clave IN ('importaciones.ver','importaciones.cargar');") 'El fresh seed no creo los permisos de importacion.'
    Assert-Equal '2' (Invoke-MySqlScalar "SELECT COUNT(*) FROM rol_permisos rp INNER JOIN roles r ON r.id=rp.rol_id INNER JOIN permisos p ON p.id=rp.permiso_id WHERE r.nombre='Administrador' AND p.clave IN ('importaciones.ver','importaciones.cargar');") 'Administrador no recibio permisos de importacion.'

    $server = Start-Process php `
        -ArgumentList @('-S', "127.0.0.1:$HttpPort", 'index.php') `
        -WorkingDirectory $temporaryRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $temporaryRoot 'writable\phase2cd-http.out.log') `
        -RedirectStandardError (Join-Path $temporaryRoot 'writable\phase2cd-http.err.log')
    $deadline = (Get-Date).AddSeconds(15)
    do {
        Start-Sleep -Milliseconds 250
        $listener = Get-NetTCPConnection -LocalPort $HttpPort -State Listen -ErrorAction SilentlyContinue
    } until ($listener -or (Get-Date) -ge $deadline)
    if (-not $listener) {
        throw 'El servidor HTTP temporal no inicio.'
    }

    $admin = New-AuthenticatedSession 'admin@mantenimiento.local' 'Admin1234'
    $companyId = Invoke-MySqlScalar 'SELECT id FROM empresas ORDER BY id LIMIT 1;'
    $branchId = Invoke-MySqlScalar "SELECT id FROM sucursales WHERE empresa_id=$companyId ORDER BY id LIMIT 1;"
    $typeId = Invoke-MySqlScalar 'SELECT id FROM tipos_equipo WHERE activo=1 AND controla_km=1 ORDER BY id LIMIT 1;'
    if ($companyId -notmatch '^\d+$' -or $branchId -notmatch '^\d+$' -or $typeId -notmatch '^\d+$') {
        throw 'No se obtuvieron los catalogos requeridos.'
    }

    Invoke-MySql "INSERT INTO sucursales (empresa_id,codigo,nombre,estado,created_at,updated_at) VALUES ($companyId,'B$runId','Sucursal B QA',1,NOW(),NOW());" | Out-Null
    $otherBranchId = Invoke-MySqlScalar "SELECT id FROM sucursales WHERE empresa_id=$companyId AND codigo='B$runId';"
    Invoke-MySql "INSERT INTO empresas (razon_social,nombre_fantasia,cuit,estado,created_at,updated_at) VALUES ('Tenant externo QA','Tenant externo QA','30$runId',1,NOW(),NOW());" | Out-Null
    $otherCompanyId = Invoke-MySqlScalar "SELECT id FROM empresas WHERE cuit='30$runId';"
    Invoke-MySql "INSERT INTO sucursales (empresa_id,codigo,nombre,estado,created_at,updated_at) VALUES ($otherCompanyId,'EXT$runId','Externa QA',1,NOW(),NOW());" | Out-Null
    $externalBranchId = Invoke-MySqlScalar "SELECT id FROM sucursales WHERE empresa_id=$otherCompanyId AND codigo='EXT$runId';"

    # Catalogo + ficha tecnica.
    Invoke-Form '/mantenimiento/catalogos/marcas' @{ nombre = "Marca QA $runId" } $admin '/mantenimiento/equipos' | Out-Null
    $brandId = Invoke-MySqlScalar "SELECT id FROM marcas WHERE empresa_id=$companyId AND nombre='Marca QA $runId';"
    if ($brandId -notmatch '^\d+$') { throw 'No se creo la marca tenant.' }
    Invoke-Form '/mantenimiento/catalogos/modelos' @{
        marca_id = $brandId
        tipo_equipo_id = $typeId
        nombre = "Modelo QA $runId"
    } $admin '/mantenimiento/equipos' | Out-Null
    $modelId = Invoke-MySqlScalar "SELECT id FROM modelos WHERE empresa_id=$companyId AND marca_id=$brandId AND nombre='Modelo QA $runId';"
    if ($modelId -notmatch '^\d+$') { throw 'No se creo el modelo compatible.' }

    $equipmentCodeA = "QA2C-A-$runId"
    $equipmentCodeB = "QA2C-B-$runId"
    foreach ($equipmentCode in @($equipmentCodeA, $equipmentCodeB)) {
        Invoke-Form '/mantenimiento/equipos' @{
            sucursal_id = $branchId
            tipo_equipo_id = $typeId
            codigo = $equipmentCode
            patente = $equipmentCode.Replace('-', '').Substring(0, [Math]::Min(12, $equipmentCode.Replace('-', '').Length))
            marca_id = $brandId
            modelo_id = $modelId
            anio = '2024'
            chasis = "CH-$equipmentCode"
            motor = "MO-$equipmentCode"
            fecha_alta = '2026-08-01'
            observaciones = 'Ficha tecnica QA 2C'
        } $admin '/mantenimiento' | Out-Null
    }
    $equipmentIdA = Invoke-MySqlScalar "SELECT id FROM equipos WHERE empresa_id=$companyId AND codigo='$equipmentCodeA';"
    $equipmentIdB = Invoke-MySqlScalar "SELECT id FROM equipos WHERE empresa_id=$companyId AND codigo='$equipmentCodeB';"
    if ($equipmentIdA -notmatch '^\d+$' -or $equipmentIdB -notmatch '^\d+$') {
        throw "No se crearon ambos equipos tecnicos. A='$equipmentIdA', B='$equipmentIdB'."
    }
    Assert-Equal "$brandId`:$modelId`:2024" (Invoke-MySqlScalar "SELECT CONCAT(marca_id,':',modelo_id,':',anio) FROM equipos WHERE id=$equipmentIdA;") 'La ficha tecnica no se persistio.'

    $equipmentList = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos?q=$equipmentCodeA" -UseBasicParsing -WebSession $admin
    if ($equipmentList.Content -notmatch [regex]::Escape($equipmentCodeA)) { throw 'El listado filtrado no muestra el equipo tenant.' }

    $relationResponse = Invoke-Form "/mantenimiento/equipos/$equipmentIdA/relaciones" @{
        equipo_relacionado_id = $equipmentIdB
        tipo_relacion = 'TRACTOR_ACOPLADO'
        desde = '2026-08-02 08:00:00'
        observaciones = 'Relacion QA'
    } $admin "/mantenimiento/equipos/$equipmentIdA"
    $relationId = Invoke-MySqlScalar "SELECT id FROM equipo_relaciones WHERE empresa_id=$companyId AND equipo_principal_id=$equipmentIdA AND equipo_relacionado_id=$equipmentIdB;"
    if ($relationId -notmatch '^\d+$') {
        $plainResponse = [regex]::Replace($relationResponse.Content, '<[^>]+>', ' ')
        $applicationLog = Get-ChildItem (Join-Path $temporaryRoot 'writable\logs') -File -Filter 'log-*.log' `
            | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        $logTail = if ($null -eq $applicationLog) { '' } else { (Get-Content $applicationLog.FullName -Tail 30) -join ' | ' }
        throw "No se creo la relacion entre equipos. Respuesta: $($plainResponse.Substring(0, [Math]::Min(800, $plainResponse.Length))). Log: $logTail"
    }
    Invoke-Form "/mantenimiento/equipos/$equipmentIdA/relaciones/$relationId/finalizar" @{
        hasta = '2026-08-03 08:00:00'
        observaciones_fin = 'Fin QA conservado'
    } $admin "/mantenimiento/equipos/$equipmentIdA" | Out-Null
    Assert-Equal '1' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipo_relaciones WHERE id=$relationId AND hasta IS NOT NULL;") 'La relacion no se finalizo conservando historial.'

    $qr = Invoke-BinaryGet "/mantenimiento/equipos/$equipmentIdA/qr.svg" $admin
    if ($qr.StatusCode -ne 200 -or $qr.ContentType -ne 'image/svg+xml' -or [System.Text.Encoding]::UTF8.GetString($qr.Bytes) -notmatch '<svg') {
        throw 'El QR autenticado no devolvio un SVG valido.'
    }

    # Adjunto privado: upload, download exacto y retiro logico sin borrar archivo.
    $pngPath = Join-Path $privateRoot 'qa-image.png'
    [System.IO.File]::WriteAllBytes($pngPath, [Convert]::FromBase64String('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))
    $upload = Invoke-Multipart "/mantenimiento/equipos/$equipmentIdA/adjuntos" @{
        tipo = 'FOTO'
        descripcion = 'Adjunto privado QA'
    } 'archivo' $pngPath 'evidencia.png' 'image/png' $admin "/mantenimiento/equipos/$equipmentIdA"
    if ($upload.StatusCode -ne 200) { throw "Upload de adjunto fallo con HTTP $($upload.StatusCode)." }
    $attachmentId = Invoke-MySqlScalar "SELECT id FROM equipo_adjuntos WHERE empresa_id=$companyId AND equipo_id=$equipmentIdA ORDER BY id DESC LIMIT 1;"
    $attachmentRelative = Invoke-MySqlScalar "SELECT ruta_privada FROM equipo_adjuntos WHERE id=$attachmentId;"
    $attachmentPath = Join-Path $attachmentRoot ($attachmentRelative.Replace('/', '\'))
    if (-not (Test-Path -LiteralPath $attachmentPath) -or $attachmentPath.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'El adjunto no quedo fuera del checkout publico temporal.'
    }
    $download = Invoke-BinaryGet "/mantenimiento/equipos/$equipmentIdA/adjuntos/$attachmentId/descargar" $admin
    $expectedHash = Get-Sha256Hex ([IO.File]::ReadAllBytes($pngPath))
    $actualHash = Get-Sha256Hex $download.Bytes
    if ($download.StatusCode -ne 200 -or $download.ContentType -ne 'image/png' -or $actualHash -ne $expectedHash) {
        throw 'La descarga privada no conserva contenido y MIME.'
    }
    Invoke-Form "/mantenimiento/equipos/$equipmentIdA/adjuntos/$attachmentId/retirar" @{ motivo = 'Retiro QA trazable' } $admin "/mantenimiento/equipos/$equipmentIdA" | Out-Null
    Assert-Equal '1' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipo_adjuntos WHERE id=$attachmentId AND retirado_at IS NOT NULL AND motivo_retiro='Retiro QA trazable';") 'El retiro logico no conservo trazabilidad.'
    if (-not (Test-Path -LiteralPath $attachmentPath)) { throw 'El retiro borro fisicamente el adjunto historico.' }
    $retiredDownload = Invoke-BinaryGet "/mantenimiento/equipos/$equipmentIdA/adjuntos/$attachmentId/descargar" $admin
    if ($retiredDownload.ContentType -eq 'image/png') { throw 'Un adjunto retirado sigue siendo descargable.' }

    # Fixtures de seguridad: sucursal no autorizada, otro tenant y permisos insuficientes.
    $password = 'QaRestricted1234'
    $passwordHash = (& php -r "echo password_hash('$password', PASSWORD_BCRYPT);")
    Invoke-MySql "INSERT INTO usuarios (empresa_id,nombre,email,password_hash,es_superadmin,activo,created_at,updated_at) VALUES ($companyId,'Responsable QA','responsable.$runId@qa.local','$passwordHash',0,1,NOW(),NOW()),($companyId,'Consulta QA','consulta.$runId@qa.local','$passwordHash',0,1,NOW(),NOW());" | Out-Null
    $responsibleId = Invoke-MySqlScalar "SELECT id FROM usuarios WHERE email='responsable.$runId@qa.local';"
    $viewerId = Invoke-MySqlScalar "SELECT id FROM usuarios WHERE email='consulta.$runId@qa.local';"
    $responsibleRoleId = Invoke-MySqlScalar "SELECT id FROM roles WHERE nombre='Responsable de mantenimiento';"
    $viewerRoleId = Invoke-MySqlScalar "SELECT id FROM roles WHERE nombre='Consulta';"
    Invoke-MySql "INSERT INTO usuario_roles (usuario_id,rol_id,created_at) VALUES ($responsibleId,$responsibleRoleId,NOW()),($viewerId,$viewerRoleId,NOW()); INSERT INTO usuario_sucursales (usuario_id,sucursal_id,created_at) VALUES ($responsibleId,$branchId,NOW()),($viewerId,$branchId,NOW());" | Out-Null

    $branchBCode = "BR-B-$runId"
    Invoke-MySql "INSERT INTO equipos (empresa_id,sucursal_id,tipo_equipo_id,codigo,estado,fecha_alta,created_at,updated_at) VALUES ($companyId,$otherBranchId,$typeId,'$branchBCode','ACTIVO','2026-08-01',NOW(),NOW()),($otherCompanyId,$externalBranchId,$typeId,'EXT-$runId','ACTIVO','2026-08-01',NOW(),NOW());" | Out-Null
    $branchBEquipmentId = Invoke-MySqlScalar "SELECT id FROM equipos WHERE empresa_id=$companyId AND codigo='$branchBCode';"
    $externalEquipmentId = Invoke-MySqlScalar "SELECT id FROM equipos WHERE empresa_id=$otherCompanyId AND codigo='EXT-$runId';"
    $responsible = New-AuthenticatedSession "responsable.$runId@qa.local" $password
    $restrictedList = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/equipos" -UseBasicParsing -WebSession $responsible
    if ($restrictedList.Content -match [regex]::Escape($branchBCode) -or $restrictedList.Content -match "EXT-$runId") {
        throw 'El listado restringido filtro datos de otra sucursal o tenant.'
    }
    $restrictedQr = Invoke-BinaryGet "/mantenimiento/equipos/$branchBEquipmentId/qr.svg" $responsible
    $externalQr = Invoke-BinaryGet "/mantenimiento/equipos/$externalEquipmentId/qr.svg" $admin
    if ($restrictedQr.ContentType -eq 'image/svg+xml' -or $externalQr.ContentType -eq 'image/svg+xml') {
        throw 'El QR expuso un equipo fuera del scope de sucursal o tenant.'
    }
    $beforeForeignAttachments = Invoke-MySqlScalar "SELECT COUNT(*) FROM equipo_adjuntos WHERE equipo_id=$branchBEquipmentId;"
    Invoke-Multipart "/mantenimiento/equipos/$branchBEquipmentId/adjuntos" @{ tipo = 'FOTO'; descripcion = 'Debe fallar' } 'archivo' $pngPath 'scope.png' 'image/png' $responsible "/mantenimiento/equipos/$equipmentIdA" | Out-Null
    Assert-Equal $beforeForeignAttachments (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipo_adjuntos WHERE equipo_id=$branchBEquipmentId;") 'El upload cruzo el scope de sucursal.'

    $viewer = New-AuthenticatedSession "consulta.$runId@qa.local" $password
    $permissionProbe = Invoke-Multipart '/mantenimiento/importaciones' @{ tipo = 'EQUIPOS' } 'archivo' $pngPath 'denied.csv' 'text/csv' $viewer '/mantenimiento/importaciones' $false
    if ($permissionProbe.StatusCode -ne 403) { throw "El filtro de importaciones.cargar no devolvio 403 (HTTP $($permissionProbe.StatusCode))." }

    # Plantilla, preview con valida/duplicada/error y confirmacion idempotente.
    $template = Invoke-BinaryGet '/mantenimiento/importaciones/plantilla/EQUIPOS' $admin
    if ($template.StatusCode -ne 200 -or [System.Text.Encoding]::UTF8.GetString($template.Bytes) -notmatch 'sucursal_codigo') {
        throw 'La plantilla CSV de equipos no se genero correctamente.'
    }
    $branchCode = Invoke-MySqlScalar "SELECT codigo FROM sucursales WHERE id=$branchId;"
    $typeName = Invoke-MySqlScalar "SELECT nombre FROM tipos_equipo WHERE id=$typeId;"
    $importCode = "IMP-$runId"
    $equipmentCsv = Join-Path $privateRoot 'equipos.csv'
    Write-Utf8File $equipmentCsv @"
sucursal_codigo,tipo_equipo,codigo,patente,marca,modelo,anio,chasis,motor,fecha_alta,observaciones
$branchCode,$typeName,$importCode,,,,,,,2026-08-04,Valida QA
$branchCode,$typeName,$equipmentCodeA,,,,,,,2026-08-04,Duplicada QA
NO_EXISTE,$typeName,ERR-$runId,,,,,,,2026-08-04,Error QA
"@
    $draftUpload = Invoke-Multipart '/mantenimiento/importaciones' @{ tipo = 'EQUIPOS' } 'archivo' $equipmentCsv 'equipos.csv' 'text/csv' $admin '/mantenimiento/importaciones'
    if ($draftUpload.StatusCode -ne 200) { throw 'No se creo el borrador de equipos.' }
    $importId = Invoke-MySqlScalar "SELECT id FROM importaciones WHERE empresa_id=$companyId AND archivo_original='equipos.csv' ORDER BY id DESC LIMIT 1;"
    Assert-Equal '1:1:1' (Invoke-MySqlScalar "SELECT CONCAT(filas_validas,':',filas_duplicadas,':',filas_error) FROM importaciones WHERE id=$importId;") 'La vista previa no clasifico valida/duplicada/error.'
    $preview = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/importaciones/$importId" -UseBasicParsing -WebSession $admin
    if ($preview.Content -notmatch 'VALIDA' -or $preview.Content -notmatch 'DUPLICADA' -or $preview.Content -notmatch 'ERROR') {
        throw 'La vista previa HTTP no muestra estados y errores por fila.'
    }
    $draftPrivatePath = Invoke-MySqlScalar "SELECT ruta_privada FROM importaciones WHERE id=$importId;"
    if (-not (Test-Path -LiteralPath $draftPrivatePath) -or -not $draftPrivatePath.StartsWith($privateRoot, [StringComparison]::OrdinalIgnoreCase)) {
        $draftDiagnostic = Invoke-MySqlScalar "SELECT CONCAT(estado,'|',COALESCE(ruta_privada,''),'|',COALESCE(resumen,'')) FROM importaciones WHERE id=$importId;"
        throw "El archivo de importacion no quedo en almacenamiento privado separado. Root='$privateRoot', DB='$draftPrivatePath', lote='$draftDiagnostic'."
    }
    Invoke-Form "/mantenimiento/importaciones/$importId/confirmar" @{} $admin '/mantenimiento/importaciones' | Out-Null
    Assert-Equal '1' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipos WHERE empresa_id=$companyId AND codigo='$importCode';") 'La confirmacion no importo la fila valida.'
    Assert-Equal 'CONFIRMADO:1' (Invoke-MySqlScalar "SELECT CONCAT(estado,':',filas_importadas) FROM importaciones WHERE id=$importId;") 'El lote no quedo confirmado.'
    if (Test-Path -LiteralPath $draftPrivatePath) { throw 'El archivo confirmado no se limpio.' }
    Invoke-Form "/mantenimiento/importaciones/$importId/confirmar" @{} $admin '/mantenimiento/importaciones' | Out-Null
    Assert-Equal '1' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipos WHERE empresa_id=$companyId AND codigo='$importCode';") 'La segunda confirmacion duplico destinos.'

    # Cancelacion explicita y ownership de mutaciones.
    $cancelCode = "CANCEL-$runId"
    $cancelCsv = Join-Path $privateRoot 'cancel.csv'
    Write-Utf8File $cancelCsv "sucursal_codigo,tipo_equipo,codigo,patente,marca,modelo,anio,chasis,motor,fecha_alta,observaciones`r`n$branchCode,$typeName,$cancelCode,,,,,,,2026-08-04,Cancelar QA`r`n"
    Invoke-Multipart '/mantenimiento/importaciones' @{ tipo = 'EQUIPOS' } 'archivo' $cancelCsv 'cancel.csv' 'text/csv' $admin '/mantenimiento/importaciones' | Out-Null
    $cancelId = Invoke-MySqlScalar "SELECT id FROM importaciones WHERE empresa_id=$companyId AND archivo_original='cancel.csv' ORDER BY id DESC LIMIT 1;"
    $cancelPrivatePath = Invoke-MySqlScalar "SELECT ruta_privada FROM importaciones WHERE id=$cancelId;"
    Invoke-Form "/mantenimiento/importaciones/$cancelId/confirmar" @{} $responsible '/mantenimiento/importaciones' | Out-Null
    Assert-Equal 'BORRADOR_VALIDADO' (Invoke-MySqlScalar "SELECT estado FROM importaciones WHERE id=$cancelId;") 'Otro usuario restringido pudo confirmar un borrador ajeno.'
    Invoke-Form "/mantenimiento/importaciones/$cancelId/cancelar" @{} $admin '/mantenimiento/importaciones' | Out-Null
    Assert-Equal 'CANCELADO:0' (Invoke-MySqlScalar "SELECT CONCAT(estado,':',filas_importadas) FROM importaciones WHERE id=$cancelId;") 'La cancelacion persistio destinos o estado incorrecto.'
    Assert-Equal '0' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipos WHERE empresa_id=$companyId AND codigo='$cancelCode';") 'La cancelacion creo el equipo destino.'
    if (Test-Path -LiteralPath $cancelPrivatePath) { throw 'La cancelacion no limpio su archivo privado.' }

    # El autor restringido puede revisar sus propios errores NULL, pero no importar otra sucursal.
    $restrictedCsv = Join-Path $privateRoot 'restricted.csv'
    Write-Utf8File $restrictedCsv "sucursal_codigo,tipo_equipo,codigo,patente,marca,modelo,anio,chasis,motor,fecha_alta,observaciones`r`nB$runId,$typeName,SCOPE-$runId,,,,,,,2026-08-04,Fuera scope`r`n"
    Invoke-Multipart '/mantenimiento/importaciones' @{ tipo = 'EQUIPOS' } 'archivo' $restrictedCsv 'restricted.csv' 'text/csv' $responsible '/mantenimiento/importaciones' | Out-Null
    $restrictedImportId = Invoke-MySqlScalar "SELECT id FROM importaciones WHERE usuario_id=$responsibleId AND archivo_original='restricted.csv' ORDER BY id DESC LIMIT 1;"
    $restrictedPreview = Invoke-WebRequest "http://127.0.0.1:$HttpPort/mantenimiento/importaciones/$restrictedImportId" -UseBasicParsing -WebSession $responsible
    if ($restrictedPreview.Content -notmatch 'ERROR') { throw 'El autor no puede ver el error sin sucursal normalizada de su borrador.' }
    Invoke-Form "/mantenimiento/importaciones/$restrictedImportId/confirmar" @{} $responsible '/mantenimiento/importaciones' | Out-Null
    Assert-Equal '0' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipos WHERE empresa_id=$companyId AND codigo='SCOPE-$runId';") 'La importacion cruzo el scope de sucursal.'

    # Lectura: ACL publica origen interno IMPORTACION y conserva fuente externa.
    $readingCsv = Join-Path $privateRoot 'lecturas.csv'
    Write-Utf8File $readingCsv "equipo_codigo,fecha_lectura,kilometraje,horometro,origen,observaciones`r`n$equipmentCodeA,2026-08-05 10:00:00,1234,,GESTYA,Fuente externa QA`r`n"
    Invoke-Multipart '/mantenimiento/importaciones' @{ tipo = 'LECTURAS' } 'archivo' $readingCsv 'lecturas.csv' 'text/csv' $admin '/mantenimiento/importaciones' | Out-Null
    $readingImportId = Invoke-MySqlScalar "SELECT id FROM importaciones WHERE empresa_id=$companyId AND archivo_original='lecturas.csv' ORDER BY id DESC LIMIT 1;"
    Invoke-Form "/mantenimiento/importaciones/$readingImportId/confirmar" @{} $admin '/mantenimiento/importaciones' | Out-Null
    Assert-Equal "IMPORTACION:IMPORTACION:$readingImportId`:FUENTE:GESTYA:$readingImportId" (Invoke-MySqlScalar "SELECT CONCAT(origen,':',referencia_origen,':',referencia_importacion_id) FROM lecturas_equipo WHERE referencia_importacion_id=$readingImportId;") 'La ACL no tradujo o trazo la lectura importada.'
    Assert-Equal '1234' (Invoke-MySqlScalar "SELECT km_actual FROM equipos WHERE id=$equipmentIdA;") 'La lectura importada no actualizo la proyeccion del equipo.'

    # Rollback real: el segundo INSERT falla; no deben quedar destino ni transiciones parciales.
    $rollbackCodeA = "RB-A-$runId"
    $rollbackCodeB = "RB-B-$runId"
    $rollbackCsv = Join-Path $privateRoot 'rollback.csv'
    Write-Utf8File $rollbackCsv @"
sucursal_codigo,tipo_equipo,codigo,patente,marca,modelo,anio,chasis,motor,fecha_alta,observaciones
$branchCode,$typeName,$rollbackCodeA,,,,,,,2026-08-06,Rollback A
$branchCode,$typeName,$rollbackCodeB,,,,,,,2026-08-06,Rollback B
"@
    Invoke-Multipart '/mantenimiento/importaciones' @{ tipo = 'EQUIPOS' } 'archivo' $rollbackCsv 'rollback.csv' 'text/csv' $admin '/mantenimiento/importaciones' | Out-Null
    $rollbackImportId = Invoke-MySqlScalar "SELECT id FROM importaciones WHERE empresa_id=$companyId AND archivo_original='rollback.csv' ORDER BY id DESC LIMIT 1;"
    $rollbackPrivatePath = Invoke-MySqlScalar "SELECT ruta_privada FROM importaciones WHERE id=$rollbackImportId;"
    Invoke-MySql "CREATE TRIGGER qa_phase2cd_fail BEFORE INSERT ON equipos FOR EACH ROW SET NEW.codigo = CASE WHEN NEW.codigo='$rollbackCodeB' THEN NULL ELSE NEW.codigo END;" | Out-Null
    Invoke-Form "/mantenimiento/importaciones/$rollbackImportId/confirmar" @{} $admin '/mantenimiento/importaciones' | Out-Null
    Assert-Equal '0' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipos WHERE empresa_id=$companyId AND codigo IN ('$rollbackCodeA','$rollbackCodeB');") 'El fallo del segundo destino no revirtio el primero.'
    Assert-Equal 'BORRADOR_VALIDADO:2' (Invoke-MySqlScalar "SELECT CONCAT(i.estado,':',SUM(f.estado='VALIDA')) FROM importaciones i INNER JOIN importacion_filas f ON f.importacion_id=i.id WHERE i.id=$rollbackImportId GROUP BY i.estado;") 'El rollback dejo transiciones parciales.'
    if (-not (Test-Path -LiteralPath $rollbackPrivatePath)) { throw 'El rollback elimino el archivo necesario para reintentar.' }
    Invoke-MySql 'DROP TRIGGER qa_phase2cd_fail;' | Out-Null
    Invoke-Form "/mantenimiento/importaciones/$rollbackImportId/confirmar" @{} $admin '/mantenimiento/importaciones' | Out-Null
    Assert-Equal '2' (Invoke-MySqlScalar "SELECT COUNT(*) FROM equipos WHERE empresa_id=$companyId AND codigo IN ('$rollbackCodeA','$rollbackCodeB');") 'El reintento posterior al rollback no importo ambas filas.'
    Assert-Equal 'CONFIRMADO:2' (Invoke-MySqlScalar "SELECT CONCAT(estado,':',filas_importadas) FROM importaciones WHERE id=$rollbackImportId;") 'El reintento no cerro el lote correctamente.'

    Write-Host 'FASE 2C/2D E2E: PASS'
    Write-Host 'Migraciones up/down/up, seed, ACL, catalogos, ficha, relaciones, QR, adjuntos e importaciones verificados.'
} finally {
    if ($null -ne $server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
        Wait-Process -Id $server.Id -Timeout 5 -ErrorAction SilentlyContinue
    }
    if ($databaseCreated) {
        try { Invoke-MySql 'DROP TRIGGER IF EXISTS qa_phase2cd_fail;' | Out-Null } catch {}
        try { Invoke-MySql "DROP DATABASE IF EXISTS $databaseName;" -WithoutDatabase | Out-Null } catch {}
    }
    foreach ($key in $dottedEnvironmentKeys) {
        [Environment]::SetEnvironmentVariable($key, $savedDottedEnvironment[$key], 'Process')
    }
    foreach ($path in @($temporaryRoot, $privateRoot)) {
        if (Test-Path -LiteralPath $path) {
            $resolved = (Resolve-Path -LiteralPath $path).Path
            if ($resolved.StartsWith($temporaryBase + '\', [StringComparison]::OrdinalIgnoreCase) `
                -and (Split-Path $resolved -Leaf) -match '^mantenimiento-fase2cd(-private)?-[a-f0-9]{8}$') {
                Remove-Item -LiteralPath $resolved -Recurse -Force
            } else {
                Write-Warning "Se omitio limpiar una ruta que no paso la validacion: $resolved"
            }
        }
    }
    $databaseRemaining = & $MySqlExecutable --protocol=tcp -h 127.0.0.1 -P 3306 -u root -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$databaseName';"
    if (($databaseRemaining | Select-Object -First 1) -ne '0') {
        Write-Warning "La base temporal $databaseName no pudo limpiarse."
    }
}
