<?php

declare(strict_types=1);

/**
 * Limpieza puntual de avisos preventivos obsoletos de TSA en produccion Ferozo.
 *
 * Solo marca RESUELTO avisos PENDIENTES cuyo ciclo ya fue superado o cuyo plan
 * dejo de estar vencido. No toca avisos vencidos reales (AF081MJ).
 *
 * Requiere el header X-Migrate-Token y el parametro apply=1.
 * Con dry=1 devuelve el plan sin escribir.
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
$apply = (string) ($_GET['apply'] ?? '') === '1';
$dry = ! $apply || (string) ($_GET['dry'] ?? '') === '1';

$resoluciones = [
    77 => ['equipo' => 'AD738EA', 'motivo' => 'Revalidado: el plan ya no está vencido (876.849 km actuales; objetivo 891.669 km).'],
    76 => ['equipo' => 'AF117ZK', 'motivo' => 'Ciclo superado: existe la OT preventiva OT-2026-000076 abierta.'],
    73 => ['equipo' => 'BEN5D63', 'motivo' => 'Ciclo superado: plan recalculado el 23/10/2026.'],
    67 => ['equipo' => 'ITV9J84', 'motivo' => 'Revalidado: el plan ya no está vencido (1.165.872 km actuales; objetivo 1.182.170 km).'],
    61 => ['equipo' => 'RHB2H00', 'motivo' => 'Ciclo superado: service cerrado en la OT preventiva OT-2026-000058.'],
    58 => ['equipo' => 'FKU3A05', 'motivo' => 'Ciclo superado: service cerrado en la OT preventiva OT-2026-000060.'],
    55 => ['equipo' => 'IUR1E71', 'motivo' => 'Ciclo superado: plan actualizado el 23/09/2026. Revisar base de kilometraje (1.104.584 km) contra lectura actual (1.079.714 km).'],
    40 => ['equipo' => 'IYT2F23', 'motivo' => 'Ciclo superado: existe la OT preventiva OT-2026-000030 abierta.'],
    37 => ['equipo' => 'HWW968', 'motivo' => 'Ciclo superado: service cerrado en la OT preventiva OT-2026-000064.'],
];

try {
    $db = db_connect();
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo conectar a la base: ' . $error->getMessage()], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$pendientesAntes = (int) $db->table('avisos_plan')
    ->where('empresa_id', $empresaId)
    ->where('estado_gestion', 'PENDIENTE')
    ->countAllResults();

$ids = array_keys($resoluciones);
$rows = $db->query(
    'SELECT a.id, a.empresa_id, a.estado_gestion, a.motivo_resolucion, a.fecha_resolucion, e.codigo AS equipo_codigo '
    . 'FROM avisos_plan a INNER JOIN equipos e ON e.id = a.equipo_id AND e.empresa_id = a.empresa_id '
    . 'WHERE a.empresa_id = ? AND a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
    array_merge([$empresaId], $ids)
)->getResultArray();

$porId = [];
foreach ($rows as $row) {
    $porId[(int) $row['id']] = $row;
}

$detalle = [];
$errores = [];
$aActualizar = [];
foreach ($resoluciones as $id => $definicion) {
    $row = $porId[$id] ?? null;
    if ($row === null) {
        $errores[] = "Aviso {$id} no encontrado en la empresa {$empresaId}.";
        continue;
    }
    if ((string) $row['equipo_codigo'] !== $definicion['equipo']) {
        $errores[] = "Aviso {$id}: se esperaba {$definicion['equipo']} y la base dice {$row['equipo_codigo']}.";
        continue;
    }
    if ($row['estado_gestion'] === 'PENDIENTE') {
        $aActualizar[$id] = $definicion['motivo'];
    } elseif ($row['estado_gestion'] === 'RESUELTO') {
        $detalle[] = ['id' => $id, 'equipo' => $row['equipo_codigo'], 'resultado' => 'ya_resuelto', 'motivo' => $row['motivo_resolucion']];
        continue;
    } else {
        $errores[] = "Aviso {$id}: estado {$row['estado_gestion']}, no se modifica.";
        continue;
    }
    $detalle[] = [
        'id' => $id,
        'equipo' => $row['equipo_codigo'],
        'resultado' => $dry ? 'a_resolver' : 'resuelto',
        'motivo' => $definicion['motivo'],
    ];
}

if ($errores !== []) {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'dry' => $dry,
        'empresa_id' => $empresaId,
        'pendientes_antes' => $pendientesAntes,
        'errores' => $errores,
        'detalle' => $detalle,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

if (! $dry && $aActualizar !== []) {
    $db->transBegin();
    try {
        $timestamp = date('Y-m-d H:i:s');
        foreach ($aActualizar as $id => $motivo) {
            $db->table('avisos_plan')
                ->where('id', $id)
                ->where('empresa_id', $empresaId)
                ->where('estado_gestion', 'PENDIENTE')
                ->update([
                    'estado_gestion' => 'RESUELTO',
                    'fecha_resolucion' => $timestamp,
                    'motivo_resolucion' => $motivo,
                    'updated_at' => $timestamp,
                ]);
            if ($db->affectedRows() !== 1) {
                throw new RuntimeException("No se pudo resolver el aviso {$id}.");
            }
        }
        $db->transCommit();
    } catch (Throwable $error) {
        $db->transRollback();
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'dry' => $dry,
            'empresa_id' => $empresaId,
            'error' => $error->getMessage(),
            'detalle' => $detalle,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit(1);
    }
}

$pendientesDespues = (int) $db->table('avisos_plan')
    ->where('empresa_id', $empresaId)
    ->where('estado_gestion', 'PENDIENTE')
    ->countAllResults();

echo json_encode([
    'ok' => true,
    'dry' => $dry,
    'empresa_id' => $empresaId,
    'resueltos' => $dry ? 0 : count($aActualizar),
    'pendientes_antes' => $pendientesAntes,
    'pendientes_despues' => $pendientesDespues,
    'detalle' => $detalle,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
