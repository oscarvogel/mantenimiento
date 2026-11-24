<?php

declare(strict_types=1);

/**
 * Carga el porcentaje de liquidacion por defecto de la empresa.
 *
 * El valor es el respaldo para los choferes que todavia no tienen fila en el
 * maestro de porcentajes. Se escribe uno por empresa, en
 * `viajes_porcentaje_liquidacion_defecto`.
 *
 * Uso en produccion Ferozo (sin SSH ni CLI):
 *   1. Subir este archivo a la raiz del release por FTPS.
 *   2. Llamarlo con el header `X-Migrate-Token`:
 *        curl -H "X-Migrate-Token: <token>" \
 *             "https://vogelconsultoria.com.ar/mantenimiento/operacion-2026-11-24-porcentaje-defecto-empresa.php"
 *      Por defecto hace DRY-RUN: no escribe nada y muestra el plan.
 *      Para aplicar:
 *        ...?apply=1
 *      Parametros opcionales:
 *        empresa_id=N     (default: detecta TSA)
 *        porcentaje=N     (default: 14.00)
 *        usuario_id=N     (default: usuario de la empresa con viajes.maestros.editar)
 *   3. Borrar el archivo por FTPS inmediatamente despues.
 */

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--')) {
            [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
            $_GET[$key] = $value;
        }
    }
}

// 1. Bootstrap minimo (mismo criterio de correccion-porcentajes-chofer-produccion.php).
$base = __DIR__;
for ($nivel = 0; $nivel < 5; $nivel++) {
    if (is_file($base . DIRECTORY_SEPARATOR . 'index.php')) {
        break;
    }
    $padre = dirname($base);
    if ($padre === $base) {
        $base = __DIR__;
        break;
    }
    $base = $padre;
}
define('FCPATH', $base . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . 'app/Config/Paths.php';
$paths = new \Config\Paths();

$envFile = FCPATH . '.env';
if (! is_file($envFile) || ! is_readable($envFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'No se encontro .env legible en la raiz del release.'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (! preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s*(.*)$/', $line, $m)) {
        continue;
    }
    $value = trim($m[2]);
    if (
        (str_starts_with($value, "'") && str_ends_with($value, "'"))
        || (str_starts_with($value, '"') && str_ends_with($value, '"'))
    ) {
        $value = substr($value, 1, -1);
    }
    $env[$m[1]] = $value;
}

$environment = $env['CI_ENVIRONMENT'] ?? 'production';

if (! $isCli) {
    if ($environment !== 'production') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Este script solo corre en produccion. CI_ENVIRONMENT=' . $environment], JSON_UNESCAPED_UNICODE);
        exit(1);
    }

    $expectedToken = $env['MIGRATE_TOKEN'] ?? '';
    $providedToken = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '';
    if ($expectedToken === '' || strlen($expectedToken) < 32) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'MIGRATE_TOKEN no esta definido en .env.'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    if (! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Token invalido o ausente.'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
}

defined('ENVIRONMENT') || define('ENVIRONMENT', $environment);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (! headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'fatal' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

require $paths->systemDirectory . '/Boot.php';
(new class extends \CodeIgniter\Boot {
    public static function bootForMigrations(\Config\Paths $paths): void
    {
        static::definePathConstants($paths);
        if (! defined('APP_NAMESPACE')) {
            static::loadConstants();
        }
        static::checkMissingExtensions();
        static::loadDotEnv($paths);
        static::defineEnvironment();
        static::loadEnvironmentBootstrap($paths);
        static::loadCommonFunctions();
        static::loadAutoloader();
        static::setExceptionHandler();
        static::initializeKint();
        static::autoloadHelpers();
    }
})::bootForMigrations($paths);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
date_default_timezone_set('America/Argentina/Buenos_Aires');

try {
    $db = db_connect();
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo conectar a la base: ' . $error->getMessage()], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$apply = (string) ($_GET['apply'] ?? '') === '1';
$porcentaje = isset($_GET['porcentaje']) && $_GET['porcentaje'] !== '' ? (float) $_GET['porcentaje'] : 14.00;

if ($porcentaje < 0 || $porcentaje > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'El porcentaje debe estar entre 0 y 100.'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- Empresa objetivo: TSA. ---
$empresaId = isset($_GET['empresa_id']) && $_GET['empresa_id'] !== '' ? (int) $_GET['empresa_id'] : null;
if ($empresaId === null) {
    $empresas = $db->table('empresas')->select('id, razon_social, nombre_fantasia')->get()->getResultArray();
    $conTsa = array_values(array_filter($empresas, static function (array $row): bool {
        $texto = mb_strtoupper(trim((string) ($row['razon_social'] ?? '') . ' ' . (string) ($row['nombre_fantasia'] ?? '')));

        return str_contains($texto, 'TSA');
    }));
    $mejor = null;
    $mejorCount = -1;
    foreach ($conTsa !== [] ? $conTsa : $empresas as $row) {
        $count = $db->table('equipos')->where('empresa_id', (int) $row['id'])->countAllResults();
        if ($count > $mejorCount) {
            $mejorCount = $count;
            $mejor = $row;
        }
    }
    if ($mejor === null) {
        http_response_code(404);
        echo json_encode(['error' => 'No se pudo identificar la empresa TSA.'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    $empresaId = (int) $mejor['id'];
}
$empresa = $db->table('empresas')->select('razon_social, nombre_fantasia')->where('id', $empresaId)->get()->getRowArray();
$empresaNombre = (string) ($empresa['razon_social'] ?? $empresa['nombre_fantasia'] ?? '');

// --- Actor: usuario de la empresa con viajes.maestros.editar. ---
$candidatos = $db->query(
    'SELECT DISTINCT u.id, u.nombre, u.email '
    . 'FROM usuarios u '
    . 'JOIN usuario_roles ur ON ur.usuario_id = u.id '
    . 'JOIN rol_permisos rp ON rp.rol_id = ur.rol_id '
    . 'JOIN permisos p ON p.id = rp.permiso_id '
    . 'WHERE u.empresa_id = ? AND u.activo = 1 '
    . 'AND p.clave = ? '
    . 'ORDER BY u.id ASC',
    [$empresaId, 'viajes.maestros.editar']
)->getResultArray();

$actorId = isset($_GET['usuario_id']) && $_GET['usuario_id'] !== '' ? (int) $_GET['usuario_id'] : null;
$actorNombre = null;
foreach ($candidatos as $candidato) {
    if ($actorId === null) {
        $actorId = (int) $candidato['id'];
    }
    if ((int) $candidato['id'] === $actorId) {
        $actorNombre = (string) $candidato['nombre'];
    }
}

// --- Estado actual y contexto para el plan. ---
$tabla = 'viajes_porcentaje_liquidacion_defecto';
if (! $db->tableExists($tabla)) {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => 'La tabla ' . $tabla . ' no existe todavia: falta aplicar la migracion 2026-11-24-160000.',
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$actual = $db->table($tabla)
    ->select('id, porcentaje, created_at, updated_at, created_by, updated_by')
    ->where('empresa_id', $empresaId)
    ->get()->getRowArray();

// Choferes de la empresa sin fila en el maestro: son los que se benefician.
$sinFilaMaestro = $db->query(
    'SELECT DISTINCT a.empleado_id, emp.nombre, emp.apellido '
    . 'FROM viaje_operativo_asignaciones a '
    . 'JOIN empleados emp ON emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id '
    . 'LEFT JOIN viajes_porcentajes_liquidacion_chofer p '
    . 'ON p.empresa_id = a.empresa_id AND p.empleado_id = a.empleado_id '
    . 'WHERE a.empresa_id = ? AND a.rol = ? AND a.empleado_id IS NOT NULL AND p.id IS NULL '
    . 'ORDER BY emp.nombre ASC',
    [$empresaId, 'CHOFER']
)->getResultArray();

$viajesSinPorcentaje = $db->query(
    'SELECT v.numero, v.estado, a.empleado_id '
    . 'FROM viajes_operativos v '
    . 'JOIN viaje_operativo_asignaciones a ON a.viaje_id = v.id AND a.empresa_id = v.empresa_id AND a.rol = ? '
    . 'WHERE v.empresa_id = ? AND a.porcentaje_liquidacion IS NULL '
    . 'ORDER BY v.numero ASC',
    ['CHOFER', $empresaId]
)->getResultArray();

$resumen = [
    'ok' => true,
    'modo' => $apply ? 'APLICA' : 'DRY_RUN',
    'empresa_id' => $empresaId,
    'empresa' => $empresaNombre,
    'tabla' => $tabla,
    'porcentaje' => $porcentaje,
    'actor' => $actorId === null ? null : ['id' => $actorId, 'nombre' => $actorNombre],
    'valor_actual' => $actual === null ? null : (float) $actual['porcentaje'],
    'cambio' => $actual === null ? 'ALTA' : (((float) $actual['porcentaje'] === $porcentaje) ? 'SIN_CAMBIO' : 'ACTUALIZACION'),
    'choferes_sin_fila_en_maestro' => count($sinFilaMaestro),
    'detalle_choferes_sin_fila' => $sinFilaMaestro,
    'viajes_sin_porcentaje_copiado' => count($viajesSinPorcentaje),
    'detalle_viajes_sin_porcentaje' => $viajesSinPorcentaje,
];

if (! $apply) {
    $resumen['aviso'] = 'DRY-RUN: no se escribio nada. Para aplicar, llamar con apply=1.';
    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(0);
}

if ($actorId === null) {
    http_response_code(409);
    $resumen['ok'] = false;
    $resumen['error'] = 'No hay un usuario de la empresa con viajes.maestros.editar. Indica usuario_id.';
    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

$now = date('Y-m-d H:i:s');

try {
    $db->transBegin();

    if ($actual === null) {
        $insertado = $db->table($tabla)->insert([
            'empresa_id' => $empresaId,
            'porcentaje' => $porcentaje,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
        if (! $insertado) {
            throw new RuntimeException('No se pudo crear el porcentaje por defecto.');
        }
        $resumen['maestro_id'] = (int) $db->insertID();
    } else {
        $actualizado = $db->table($tabla)
            ->where('empresa_id', $empresaId)
            ->update([
                'porcentaje' => $porcentaje,
                'updated_at' => $now,
                'updated_by' => $actorId,
            ]);
        if ($actualizado === false) {
            throw new RuntimeException('No se pudo actualizar el porcentaje por defecto.');
        }
        $resumen['maestro_id'] = (int) $actual['id'];
    }

    if ($db->transStatus() === false) {
        throw new RuntimeException('La transaccion quedo en un estado invalido.');
    }
    $db->transCommit();
} catch (Throwable $error) {
    $db->transRollback();
    http_response_code(500);
    $resumen['ok'] = false;
    $resumen['error'] = $error->getMessage();
    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

$verificacion = $db->table($tabla)
    ->select('empresa_id, porcentaje, created_at, updated_at, created_by, updated_by')
    ->where('empresa_id', $empresaId)
    ->get()->getRowArray();

$resumen['verificacion'] = $verificacion;
$resumen['aviso'] = 'Porcentaje por defecto cargado. Los choferes sin fila en el maestro usan este valor.';
echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
