<?php

declare(strict_types=1);

/**
 * Correccion de porcentajes de liquidacion de chofer (produccion Ferozo).
 *
 * Corrige el hueco que quedo cuando el maestro de porcentajes se cargo en
 * lote el 04/11/2026: los choferes dados de alta DESPUES de esa fecha no
 * tienen fila en `viajes_porcentajes_liquidacion_chofer`, y sus viajes
 * quedan sin comision y sin margen ("Sin datos").
 *
 * Que hace, en una transaccion:
 *   1. Fija `porcentaje_liquidacion` = 14.00 en la asignacion CHOFER de los
 *      viajes abiertos que quedaron sin porcentaje (mismo efecto que el
 *      boton "Guardar %" del detalle del viaje).
 *   2. Da de alta la fila faltante en el maestro de porcentajes para que los
 *      proximos viajes se precarguen solos.
 *
 * NO toca gastos ni combustible: la imputacion de un gasto a un tramo es una
 * decision operativa, no un dato mecanico.
 *
 * Uso en produccion Ferozo (sin SSH ni CLI):
 *   1. Subir este archivo a la raiz del release por FTPS.
 *   2. Llamarlo con el header `X-Migrate-Token`:
 *        curl -H "X-Migrate-Token: <token>" \
 *             "https://vogelconsultoria.com.ar/mantenimiento/correccion-porcentajes-chofer-produccion.php"
 *      Por defecto hace DRY-RUN: no escribe nada y muestra el plan.
 *      Para aplicar:
 *        ...?apply=1
 *      Parametros opcionales:
 *        empresa_id=N     (default: detecta TSA)
 *        usuario_id=N     (default: usuario con viajes.operar + viajes.maestros.editar)
 *        vigente_desde=   (default: 2026-11-04) inicio de la fila de maestro
 *        porcentaje=      (default: 14.00)
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

// 1. Bootstrap minimo (mismo criterio de sonda-viajes-operativos.php).
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

// El corrector tiene que mostrar el error real si algo falla en produccion.
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

/**
 * Filtra la lista de columnas contra el esquema real desplegado: la base de
 * produccion puede diferir de la migracion.
 *
 * @param list<string> $fields
 * @return list<string>
 */
function columnas($db, string $table, array $fields): array
{
    static $cache = [];
    if (! isset($cache[$table])) {
        try {
            $cache[$table] = $db->getFieldNames($table);
        } catch (Throwable) {
            $cache[$table] = [];
        }
    }
    if ($cache[$table] === []) {
        return $fields;
    }
    $existentes = array_values(array_filter($fields, static fn (string $field): bool => in_array($field, $cache[$table], true)));

    return $existentes === [] ? $fields : $existentes;
}

/**
 * Reproduce la resolucion de porcentaje del maestro que hace la app
 * (CodeIgniterOperationalTripReadModel::masterPercentagesForDrivers):
 * una fila activa vigente a la fecha de salida del viaje; la mas reciente gana.
 *
 * @param list<array<string,mixed>> $filas
 * @return array<string,mixed>|null
 */
function maestroVigente(array $filas, string $fecha): ?array
{
    if ($fecha === '') {
        return null;
    }
    foreach ($filas as $fila) {
        if ((int) ($fila['activo'] ?? 0) !== 1) {
            continue;
        }
        if (substr((string) $fila['vigente_desde'], 0, 10) > $fecha) {
            continue;
        }
        $hasta = $fila['vigente_hasta'] ?? null;
        if ($hasta !== null && substr((string) $hasta, 0, 10) < $fecha) {
            continue;
        }

        return $fila;
    }

    return null;
}

$apply = (string) ($_GET['apply'] ?? '') === '1';
$porcentaje = isset($_GET['porcentaje']) && $_GET['porcentaje'] !== '' ? (float) $_GET['porcentaje'] : 14.00;
// Por defecto se replica la fecha de inicio del maestro ya cargado (04/11/2026),
// para que las filas nuevas queden homogeneous con las 25 existentes.
$vigenteDesde = isset($_GET['vigente_desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['vigente_desde'])
    ? (string) $_GET['vigente_desde']
    : '2026-11-04';

if ($porcentaje < 0 || $porcentaje > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'El porcentaje debe estar entre 0 y 100.'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

// --- Empresa objetivo: TSA. ---
$empresaId = isset($_GET['empresa_id']) && $_GET['empresa_id'] !== '' ? (int) $_GET['empresa_id'] : null;
if ($empresaId === null) {
    $empresas = $db->table('empresas')
        ->select('id, razon_social, nombre_fantasia')
        ->get()->getResultArray();
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

// --- Actor: usuario con viajes.operar y viajes.maestros.editar. ---
$columnasUsuarios = columnas($db, 'usuarios', ['id', 'nombre', 'email', 'empresa_id', 'activo', 'deleted_at']);
$filtroBaja = in_array('deleted_at', $columnasUsuarios, true) ? ' AND u.deleted_at IS NULL' : '';
$candidatos = $db->query(
    'SELECT DISTINCT u.id, u.nombre, u.email '
    . 'FROM usuarios u '
    . 'JOIN usuario_roles ur ON ur.usuario_id = u.id '
    . 'JOIN rol_permisos rp ON rp.rol_id = ur.rol_id '
    . 'JOIN permisos p ON p.id = rp.permiso_id '
    . 'WHERE u.empresa_id = ? AND u.activo = 1' . $filtroBaja . ' '
    . 'AND p.clave IN (?, ?) '
    . 'ORDER BY u.id ASC',
    [$empresaId, 'viajes.operar', 'viajes.maestros.editar']
)->getResultArray();

if ($candidatos === []) {
    // Sin usuario de la empresa: puede ser el Superadministrador global
    // (empresa_id NULL), que tiene los permisos por rol.
    $candidatos = $db->query(
        'SELECT DISTINCT u.id, u.nombre, u.email '
        . 'FROM usuarios u '
        . 'JOIN usuario_roles ur ON ur.usuario_id = u.id '
        . 'JOIN rol_permisos rp ON rp.rol_id = ur.rol_id '
        . 'JOIN permisos p ON p.id = rp.permiso_id '
        . 'WHERE u.empresa_id IS NULL AND u.activo = 1' . $filtroBaja . ' '
        . 'AND p.clave IN (?, ?) '
        . 'ORDER BY u.id ASC',
        ['viajes.operar', 'viajes.maestros.editar']
    )->getResultArray();
}

$actorId = null;
if (isset($_GET['usuario_id']) && $_GET['usuario_id'] !== '') {
    $actorId = (int) $_GET['usuario_id'];
} elseif ($candidatos !== []) {
    $actorId = (int) $candidatos[0]['id'];
}
$actorNombre = null;
$actorEmail = null;
foreach ($candidatos as $candidato) {
    if ($actorId !== null && (int) $candidato['id'] === $actorId) {
        $actorNombre = (string) $candidato['nombre'];
        $actorEmail = (string) $candidato['email'];
    }
}

// --- Viajes abiertos con chofer sin porcentaje resuelto. ---
$viajes = $db->query(
    'SELECT v.id, v.numero, v.estado, '
    . 'COALESCE(v.fecha_salida_real, v.fecha_salida_planificada) AS salida, '
    . 'a.id AS asignacion_id, a.empleado_id, a.porcentaje_liquidacion, '
    . 'emp.nombre AS chofer_nombre, emp.apellido AS chofer_apellido, emp.activo AS chofer_activo '
    . 'FROM viajes_operativos v '
    . 'JOIN viaje_operativo_asignaciones a ON a.viaje_id = v.id AND a.empresa_id = v.empresa_id AND a.rol = ? '
    . 'LEFT JOIN empleados emp ON emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id '
    . 'WHERE v.empresa_id = ? AND v.estado IN (?, ?) '
    . 'ORDER BY v.numero ASC',
    ['CHOFER', $empresaId, 'PLANIFICADO', 'EN_CURSO']
)->getResultArray();

$maestroPorEmpleado = [];
$maestroRows = $db->table('viajes_porcentajes_liquidacion_chofer')
    ->select('id, empleado_id, porcentaje, vigente_desde, vigente_hasta, activo')
    ->where('empresa_id', $empresaId)
    ->orderBy('empleado_id', 'ASC')
    ->orderBy('vigente_desde', 'DESC')
    ->get()->getResultArray();
foreach ($maestroRows as $row) {
    $maestroPorEmpleado[(int) $row['empleado_id']][] = $row;
}

// --- Plan: que se haria, sin escribir nada. ---
$plan = [];
$viajesAfectados = [];
$empleadosANuevaFila = [];

foreach ($viajes as $viaje) {
    $empleadoId = (int) ($viaje['empleado_id'] ?? 0);
    if ($empleadoId <= 0) {
        continue;
    }
    $filasMaestro = $maestroPorEmpleado[$empleadoId] ?? [];
    $vigente = maestroVigente($filasMaestro, substr((string) ($viaje['salida'] ?? ''), 0, 10));

    $yaResuelto = $viaje['porcentaje_liquidacion'] !== null || $vigente !== null;
    if ($yaResuelto) {
        continue;
    }

    $viajesAfectados[] = [
        'viaje_id' => (int) $viaje['id'],
        'numero' => (int) $viaje['numero'],
        'estado' => $viaje['estado'],
        'salida' => $viaje['salida'],
        'asignacion_id' => (int) $viaje['asignacion_id'],
        'empleado_id' => $empleadoId,
        'chofer' => trim((string) ($viaje['chofer_nombre'] ?? '') . ' ' . (string) ($viaje['chofer_apellido'] ?? '')),
        'porcentaje_actual_en_viaje' => $viaje['porcentaje_liquidacion'],
        'maestro_para_este_chofer' => $filasMaestro,
    ];

    $plan[] = [
        'accion' => 'FIJAR_PORCENTAJE_EN_VIAJE',
        'viaje_id' => (int) $viaje['id'],
        'viaje_numero' => (int) $viaje['numero'],
        'chofer' => trim((string) ($viaje['chofer_nombre'] ?? '') . ' ' . (string) ($viaje['chofer_apellido'] ?? '')),
        'empleado_id' => $empleadoId,
        'antes' => null,
        'despues' => $porcentaje,
        'tabla' => 'viaje_operativo_asignaciones',
        'fila' => (int) $viaje['asignacion_id'],
    ];
}

// Choferes reales de la empresa: los que aparecen como CHOFER en algun viaje
// operativo. Todos necesitan fila en el maestro: sin ella, un viaje nuevo
// puede quedar sin porcentaje y la comision muestra "Sin datos".
$choferesOperativos = $db->query(
    'SELECT DISTINCT a.empleado_id, emp.nombre, emp.apellido, emp.activo, emp.created_at '
    . 'FROM viaje_operativo_asignaciones a '
    . 'JOIN empleados emp ON emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id '
    . 'WHERE a.empresa_id = ? AND a.rol = ? AND a.empleado_id IS NOT NULL '
    . 'ORDER BY emp.nombre ASC',
    [$empresaId, 'CHOFER']
)->getResultArray();

foreach ($choferesOperativos as $chofer) {
    $empleadoId = (int) $chofer['empleado_id'];
    if (isset($maestroPorEmpleado[$empleadoId])) {
        continue;
    }
    $nombre = trim((string) ($chofer['nombre'] ?? '') . ' ' . (string) ($chofer['apellido'] ?? ''));
    $empleadosANuevaFila[] = [
        'empleado_id' => $empleadoId,
        'chofer' => $nombre,
        'chofer_alta' => $chofer['created_at'] ?? null,
        'chofer_activo' => (int) ($chofer['activo'] ?? 0),
    ];
    $plan[] = [
        'accion' => 'ALTA_EN_MAESTRO',
        'empleado_id' => $empleadoId,
        'chofer' => $nombre,
        'tabla' => 'viajes_porcentajes_liquidacion_chofer',
        'porcentaje' => $porcentaje,
        'vigente_desde' => $vigenteDesde,
        'vigente_hasta' => null,
        'activo' => 1,
        'motivo' => 'El chofer no tiene ninguna fila en el maestro de porcentajes de liquidacion.',
    ];
}

// Choferes de los viajes afectados que ya tienen filas en el maestro.
$empleadosDeViajesAfectados = array_values(array_unique(array_map(
    static fn (array $viaje): int => (int) $viaje['empleado_id'],
    $viajesAfectados,
)));

foreach ($empleadosDeViajesAfectados as $empleadoId) {
    $filasMaestro = $maestroPorEmpleado[$empleadoId] ?? [];
    if ($filasMaestro === []) {
        // El alta en el maestro ya quedo planificada arriba.
        continue;
    }
    $empleado = $db->table('empleados')->select('id, nombre, apellido')->where('id', $empleadoId)->get()->getRowArray();
    $nombre = trim((string) ($empleado['nombre'] ?? '') . ' ' . (string) ($empleado['apellido'] ?? ''));

    // Ya tiene filas: solo se completaria el tramo no cubierto. La app
    // rechaza solapamientos, asi que no se toca el maestro en este caso:
    // el porcentaje queda fijo en el viaje.
    $plan[] = [
        'accion' => 'SOLO_VIAJE',
        'empleado_id' => $empleadoId,
        'chofer' => $nombre,
        'motivo' => 'El maestro ya tiene filas y la app no admite periodos solapados: el porcentaje se fija solo en el viaje.',
        'maestro_actual' => $filasMaestro,
    ];
}

/**
 * Como quedaria la comision y el margen de cada tramo afectado si se aplica
 * el porcentaje (mismo calculo que GetOperationalTrip::financials).
 *
 * @param list<array<string,mixed>> $viajesAfectados
 * @return list<array<string,mixed>>
 */
function estimarTramos($db, int $empresaId, float $porcentaje, array $viajesAfectados): array
{
    $salida = [];
    foreach ($viajesAfectados as $viaje) {
        $tramos = $db->table('viaje_operativo_tramos')
            ->select(implode(', ', columnas($db, 'viaje_operativo_tramos', [
                'id', 'orden', 'origen', 'destino', 'flete_facturacion_ars', 'flete_liquidacion_ars', 'anulado_at',
            ])))
            ->where('empresa_id', $empresaId)
            ->where('viaje_id', $viaje['viaje_id'])
            ->orderBy('orden', 'ASC')
            ->get()->getResultArray();

        $costosPorTramo = [];
        foreach (['viaje_operativo_gastos', 'viaje_operativo_combustible'] as $tabla) {
            $filas = $db->table($tabla)
                ->select(implode(', ', columnas($db, $tabla, ['tramo_id', 'importe_ars'])))
                ->where('empresa_id', $empresaId)
                ->where('viaje_id', $viaje['viaje_id'])
                ->get()->getResultArray();
            foreach ($filas as $fila) {
                $tramoId = (int) ($fila['tramo_id'] ?? 0);
                if ($tramoId <= 0) {
                    continue;
                }
                $costosPorTramo[$tramoId] = ($costosPorTramo[$tramoId] ?? 0.0) + (float) ($fila['importe_ars'] ?? 0);
            }
        }

        $tramosDetalle = [];
        foreach ($tramos as $tramo) {
            $anulado = ($tramo['anulado_at'] ?? null) !== null;
            $liquidacion = $tramo['flete_liquidacion_ars'] === null ? null : (float) $tramo['flete_liquidacion_ars'];
            $facturacion = $tramo['flete_facturacion_ars'] === null ? null : (float) $tramo['flete_facturacion_ars'];
            $comision = ($liquidacion === null || $anulado) ? null : round($liquidacion * $porcentaje / 100, 2);
            $costos = $costosPorTramo[(int) $tramo['id']] ?? 0.0;
            $margen = ($comision === null || $facturacion === null) ? null : round($facturacion - $costos - $comision, 2);

            $tramosDetalle[] = [
                'orden' => $tramo['orden'],
                'origen' => $tramo['origen'],
                'destino' => $tramo['destino'],
                'flete_facturacion_ars' => $facturacion,
                'flete_liquidacion_ars' => $liquidacion,
                'costos_asignados_ars' => round($costos, 2),
                'comision_ars' => $comision,
                'margen_ars' => $margen,
                'anulado' => $anulado,
            ];
        }

        $salida[] = [
            'viaje_id' => $viaje['viaje_id'],
            'viaje_numero' => $viaje['numero'],
            'chofer' => $viaje['chofer'],
            'porcentaje' => $porcentaje,
            'tramos' => $tramosDetalle,
        ];
    }

    return $salida;
}

$estimacion = estimarTramos($db, $empresaId, $porcentaje, $viajesAfectados);

$resumen = [
    'ok' => true,
    'modo' => $apply ? 'APLICA' : 'DRY_RUN',
    'empresa_id' => $empresaId,
    'empresa' => $empresaNombre,
    'porcentaje' => $porcentaje,
    'vigente_desde' => $vigenteDesde,
    'actor' => $actorId === null
        ? null
        : ['id' => $actorId, 'nombre' => $actorNombre, 'email' => $actorEmail],
    'candidatos_actor' => $candidatos,
    'viajes_abiertos_con_chofer' => count($viajes),
    'viajes_sin_porcentaje' => count($viajesAfectados),
    'detalle_viajes_sin_porcentaje' => $viajesAfectados,
    'altas_maestro_previstas' => $empleadosANuevaFila,
    'estimacion_si_se_aplica' => $estimacion,
    'plan' => $plan,
];

if (! $apply) {
    $resumen['aviso'] = 'DRY-RUN: no se escribio nada. Para aplicar, llamar con apply=1.';
    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(0);
}

if ($actorId === null) {
    http_response_code(409);
    $resumen['ok'] = false;
    $resumen['error'] = 'No hay un usuario con viajes.operar y viajes.maestros.editar. Indica usuario_id.';
    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

if ($plan === []) {
    $resumen['aviso'] = 'No habia nada que corregir.';
    echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(0);
}

// --- Aplicacion, en una transaccion. ---
$now = date('Y-m-d H:i:s');
$aplicados = [];

try {
    $db->transBegin();

    foreach ($plan as $accion) {
        if ($accion['accion'] === 'FIJAR_PORCENTAJE_EN_VIAJE') {
            $ok = $db->table('viaje_operativo_asignaciones')
                ->where('id', $accion['fila'])
                ->where('empresa_id', $empresaId)
                ->update(['porcentaje_liquidacion' => $porcentaje]);
            if ($ok === false || $db->affectedRows() < 1) {
                throw new RuntimeException(
                    'No se pudo fijar el porcentaje del viaje ' . $accion['viaje_numero'] . '.',
                );
            }
            $aplicados[] = $accion;
            continue;
        }

        if ($accion['accion'] === 'ALTA_EN_MAESTRO') {
            $insertado = $db->table('viajes_porcentajes_liquidacion_chofer')->insert([
                'empresa_id' => $empresaId,
                'empleado_id' => $accion['empleado_id'],
                'porcentaje' => $porcentaje,
                'vigente_desde' => $accion['vigente_desde'],
                'vigente_hasta' => null,
                'activo' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            if (! $insertado) {
                throw new RuntimeException(
                    'No se pudo crear la fila de maestro para el empleado ' . $accion['empleado_id'] . '.',
                );
            }
            $aplicados[] = $accion + ['maestro_id' => (int) $db->insertID()];
        }
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

// --- Verificacion posterior: los viajes deben resolver porcentaje y comision. ---
$verificacion = [];
foreach ($viajesAfectados as $viaje) {
    $asignacion = $db->table('viaje_operativo_asignaciones')
        ->select('porcentaje_liquidacion')
        ->where('empresa_id', $empresaId)
        ->where('viaje_id', $viaje['viaje_id'])
        ->where('empleado_id', $viaje['empleado_id'])
        ->where('rol', 'CHOFER')
        ->get()->getRowArray();
    $maestro = $db->table('viajes_porcentajes_liquidacion_chofer')
        ->select('porcentaje, vigente_desde, vigente_hasta, activo')
        ->where('empresa_id', $empresaId)
        ->where('empleado_id', $viaje['empleado_id'])
        ->orderBy('vigente_desde', 'DESC')
        ->get()->getResultArray();
    $verificacion[] = [
        'viaje_id' => $viaje['viaje_id'],
        'viaje_numero' => $viaje['numero'],
        'chofer' => $viaje['chofer'],
        'porcentaje_en_viaje' => $asignacion['porcentaje_liquidacion'] ?? null,
        'maestro' => $maestro,
    ];
}

$resumen['aplicados'] = count($aplicados);
$resumen['verificacion'] = $verificacion;
$resumen['comision_por_tramo_despues'] = estimarTramos($db, $empresaId, $porcentaje, $viajesAfectados);
$resumen['aviso'] = 'Correccion aplicada. Los viajes afectados ahora calculan comision y margen.';
echo json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
