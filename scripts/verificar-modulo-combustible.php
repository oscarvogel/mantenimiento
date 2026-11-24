<?php

declare(strict_types=1);

/**
 * Verificacion de SOLO LECTURA del modulo de Combustible contra la base real.
 *
 * Corre los casos de uso de verdad, resueltos por el composition root
 * (`service('panelCombustible')` y `service('conciliacionCombustible')`, los
 * mismos que usa el controller FuelModule) contra MySQL de produccion, y
 * coteja el resultado con un SELECT directo a `viaje_operativo_combustible`.
 *
 * No escribe nada. El script se borra del release al terminar.
 *
 * Uso (produccion Ferozo, sin CLI):
 *   curl -H "X-Migrate-Token: <token>" \
 *        "https://vogelconsultoria.com.ar/mantenimiento/verificar-modulo-combustible.php?empresa_id=4&dias=30"
 */

$isCli = PHP_SAPI === 'cli';

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
        echo json_encode(['error' => 'Este script solo corre en produccion.'], JSON_UNESCAPED_UNICODE);
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

$empresaId = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : 4;
$dias = isset($_GET['dias']) ? (int) $_GET['dias'] : 30;
if ($dias <= 0 || $dias > 90) {
    $dias = 30;
}

try {
    $db = db_connect();
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo conectar a la base: ' . $error->getMessage()], JSON_UNESCAPED_UNICODE);
    exit(1);
}

try {
    $empresa = $db->table('empresas')->select('id, razon_social')->where('id', $empresaId)->get()->getRowArray();
    if ($empresa === null) {
        throw new \RuntimeException('No existe la empresa ' . $empresaId . '.');
    }

    // Contexto sintetico: misma empresa, todas las sucursales y los tres
    // permisos que el modulo usa (los dos de lectura de viajes y el gerencial
    // de la conciliacion con el sensor).
    $actor = new \App\Application\Identity\ActorContext(
        1,
        $empresaId,
        false,
        true,
        [],
        ['viajes.ver', 'viajes.operar', 'gerencial.flota.ver'],
        [],
    );

    // 1. El panel, tal como lo arma el controller FuelModule.
    $panel = service('panelCombustible')->execute($actor, $dias, null);

    // 2. La conciliacion contra el sensor (solo con gerencial.flota.ver).
    $conciliacion = service('conciliacionCombustible')->execute($actor, $dias, null);

    // 3. Cotejo directo contra la tabla: la fuente de verdad.
    $tablaCombustible = $db->prefixTable('viaje_operativo_combustible');
    $tablaViajes = $db->prefixTable('viajes_operativos');

    $sqlTabla = "SELECT COUNT(*) AS cargas, COALESCE(SUM(litros), 0) AS litros, "
        . 'COALESCE(SUM(importe_ars), 0) AS importe_ars, '
        . 'SUM(CASE WHEN importe IS NULL THEN 1 ELSE 0 END) AS cargas_sin_precio, '
        . 'COALESCE(SUM(CASE WHEN importe IS NULL THEN litros ELSE 0 END), 0) AS litros_sin_precio '
        . "FROM {$tablaCombustible} WHERE empresa_id = ? AND deleted_at IS NULL";
    $tabla = $db->query($sqlTabla, [$empresaId])->getRowArray();

    // La misma ventana que usa el caso de uso (salida real cargada, viaje no
    // cancelado, dentro del periodo), para comparar mantera con el panel.
    $desde = date('Y-m-d', strtotime('-' . $dias . ' days'));
    $sqlVentana = "SELECT COUNT(*) AS cargas, COALESCE(SUM(f.litros), 0) AS litros, "
        . 'COALESCE(SUM(f.importe_ars), 0) AS importe_ars, '
        . 'SUM(CASE WHEN f.importe IS NULL THEN 1 ELSE 0 END) AS cargas_sin_precio, '
        . 'COALESCE(SUM(CASE WHEN f.importe IS NULL THEN f.litros ELSE 0 END), 0) AS litros_sin_precio '
        . "FROM {$tablaCombustible} f "
        . "INNER JOIN {$tablaViajes} v ON v.id = f.viaje_id AND v.empresa_id = f.empresa_id "
        . "WHERE f.empresa_id = ? AND f.deleted_at IS NULL "
        . "AND v.estado <> 'CANCELADO' AND v.fecha_salida_real IS NOT NULL "
        . 'AND DATE(v.fecha_salida_real) <= CURDATE() '
        . 'AND (v.fecha_regreso_real IS NULL OR DATE(v.fecha_regreso_real) >= ?)';
    $ventana = $db->query($sqlVentana, [$empresaId, $desde])->getRowArray();

    $columnas = [];
    foreach ($db->query('SHOW COLUMNS FROM ' . $tablaCombustible)->getResultArray() as $columna) {
        $columnas[] = ($columna['Field'] ?? '') . ' ' . ($columna['Type'] ?? '') . ' null=' . ($columna['Null'] ?? '');
    }

    $totales = $panel['totales'];
    $coincide = [
        'cargas' => (int) $ventana['cargas'] === (int) $totales['cargas'],
        'litros' => abs((float) $ventana['litros'] - (float) $totales['litros']) < 0.01,
        'importe_ars' => abs((float) $ventana['importe_ars'] - (float) $totales['importe_ars']) < 0.01,
        'cargas_sin_precio' => (int) $ventana['cargas_sin_precio'] === (int) $totales['cargas_sin_precio'],
        'litros_sin_precio' => abs((float) $ventana['litros_sin_precio'] - (float) $totales['litros_sin_precio']) < 0.01,
    ];

    echo json_encode([
        'ok' => true,
        'motor' => $db->DBDriver,
        'empresa' => $empresa,
        'dias' => $dias,
        'panel_periodo' => $panel['periodo'],
        'panel_totales' => $totales,
        'tabla_total_empresa' => $tabla,
        'tabla_en_la_ventana_del_panel' => $ventana,
        'coincide_panel_con_tabla' => $coincide,
        'coincide_total' => ! in_array(false, $coincide, true),
        'conciliacion_totales' => $conciliacion['totales'],
        'conciliacion_con_veredicto' => array_values(array_filter(
            $conciliacion['viajes'],
            static fn (array $v): bool => ($v['estado'] ?? '') !== 'SIN_DATOS',
        )),
        'panel_viajes_con_carga' => array_values(array_filter(
            $panel['viajes'],
            static fn (array $v): bool => $v['cargas'] > 0,
        )),
        'panel_viajes_sin_carga_muestra' => array_slice(array_values(array_filter(
            $panel['viajes'],
            static fn (array $v): bool => $v['cargas'] === 0,
        )), 0, 5),
        'panel_sin_salida_real' => $panel['sin_salida_real'],
        'columnas_tabla_combustible' => $columnas,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $error->getMessage(),
        'file' => $error->file,
        'line' => $error->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
