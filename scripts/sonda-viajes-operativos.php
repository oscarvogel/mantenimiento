<?php

declare(strict_types=1);

/**
 * Sonda de SOLO LECTURA sobre viajes operativos.
 *
 * Pensada para produccion Ferozo, donde no hay SSH ni CLI:
 *   1. Subir este archivo a la raiz del release por FTPS (al lado de index.php).
 *   2. Llamarlo con el header `X-Migrate-Token` (valor de MIGRATE_TOKEN del .env
 *      de produccion):
 *        curl -H "X-Migrate-Token: <token>" \
 *             "https://vogelconsultoria.com.ar/mantenimiento/sonda-viajes-operativos.php?patente=OIU270"
 *      Parametros opcionales:
 *        patente=XXX     Dominio o patente del equipo (case insensitive).
 *        numero=N        Numero de viaje operativo a inspeccionar.
 *        empresa_id=N    Por defecto: detecta TSA.
 *        format=json|text (default json)
 *   3. Borrar el archivo por FTPS inmediatamente despues.
 *
 * No escribe nada en la base: todas las consultas son SELECT.
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

// 1. Bootstrap minimo (mismo criterio de sonda-importacion.php / migrate.php).
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
    if ($isCli) {
        fwrite(STDERR, "ERROR: no se encontro .env legible en la raiz del release.\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: no se encontro .env legible en la raiz del release.\n";
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
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR: este script solo corre en produccion. CI_ENVIRONMENT='{$environment}'.\n";
        exit(1);
    }

    $expectedToken = $env['MIGRATE_TOKEN'] ?? '';
    $providedToken = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '';
    if ($expectedToken === '' || strlen($expectedToken) < 32) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR: MIGRATE_TOKEN no esta definido en .env.\n";
        exit(1);
    }
    if (! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR: token invalido o ausente (header X-Migrate-Token).\n";
        exit(1);
    }
}

defined('ENVIRONMENT') || define('ENVIRONMENT', $environment);

// La sonda necesita mostrar el error real para poder depurar en produccion.
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

$format = strtolower((string) ($_GET['format'] ?? 'json'));
$format = in_array($format, ['json', 'text'], true) ? $format : 'json';
foreach (['json' => 'application/json; charset=utf-8', 'text' => 'text/plain; charset=utf-8'] as $key => $mime) {
    if ($key === $format) {
        header('Content-Type: ' . $mime);
    }
}
header('Cache-Control: no-store');
date_default_timezone_set('America/Argentina/Buenos_Aires');

try {
    $db = db_connect();
} catch (Throwable $error) {
    http_response_code(500);
    echo $format === 'json'
        ? json_encode(['error' => 'No se pudo conectar a la base: ' . $error->getMessage()], JSON_UNESCAPED_UNICODE)
        : 'ERROR: No se pudo conectar a la base: ' . $error->getMessage() . "\n";
    exit(1);
}

/**
 * Filtra la lista de columnas contra el esquema real: la sonda tiene que
 * sobrevivir a diferencias entre la migracion y la base desplegada.
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

$patente = trim((string) ($_GET['patente'] ?? ''));
$numero = isset($_GET['numero']) && $_GET['numero'] !== '' ? (int) $_GET['numero'] : null;
$empresaId = isset($_GET['empresa_id']) && $_GET['empresa_id'] !== '' ? (int) $_GET['empresa_id'] : null;

// --- Empresa objetivo (por defecto, la que tiene mas equipos: TSA). ---
if ($empresaId === null) {
    $candidatas = $db->table('empresas')
        ->select('id, razon_social, nombre_fantasia')
        ->get()->getResultArray();
    $conTsa = array_values(array_filter($candidatas, static function (array $row): bool {
        $texto = mb_strtoupper(trim((string) ($row['razon_social'] ?? '') . ' ' . (string) ($row['nombre_fantasia'] ?? '')));

        return str_contains($texto, 'TSA');
    }));
    $pool = $conTsa !== [] ? $conTsa : $candidatas;
    $mejor = null;
    $mejorCount = -1;
    foreach ($pool as $row) {
        $count = $db->table('equipos')->where('empresa_id', (int) $row['id'])->countAllResults();
        if ($count > $mejorCount) {
            $mejorCount = $count;
            $mejor = $row;
        }
    }
    if ($mejor === null) {
        http_response_code(404);
        echo $format === 'json'
            ? json_encode(['error' => 'No se pudo identificar la empresa.'], JSON_UNESCAPED_UNICODE)
            : "ERROR: No se pudo identificar la empresa.\n";
        exit(1);
    }
    $empresaId = (int) $mejor['id'];
    $empresaNombre = (string) ($mejor['razon_social'] ?? $mejor['nombre_fantasia'] ?? '');
} else {
    $fila = $db->table('empresas')->select('razon_social, nombre_fantasia')->where('id', $empresaId)->get()->getRowArray();
    $empresaNombre = (string) ($fila['razon_social'] ?? $fila['nombre_fantasia'] ?? '');
}

// --- Equipos que matchean la patente pedida (o todos si no se informo). ---
$equipos = [];
if ($patente !== '') {
    $equipos = $db->table('equipos')
        ->select(implode(', ', columnas($db, 'equipos', [
            'id', 'codigo', 'patente', 'estado', 'sucursal_id', 'km_actual',
        ])))
        ->where('empresa_id', $empresaId)
        ->groupStart()
        ->where('patente', $patente)
        ->orWhere('codigo', $patente)
        ->groupEnd()
        ->get()->getResultArray();
    if ($equipos === []) {
        $equipos = $db->table('equipos')
            ->select(implode(', ', columnas($db, 'equipos', [
                'id', 'codigo', 'patente', 'estado', 'sucursal_id', 'km_actual',
            ])))
            ->where('empresa_id', $empresaId)
            ->where('patente LIKE', '%' . $patente . '%')
            ->get()->getResultArray();
    }
}
$equipoIds = array_map(static fn (array $row): int => (int) $row['id'], $equipos);

// --- Viajes operativos candidatos. ---
$where = 'v.empresa_id = ?';
$binds = [$empresaId];
if ($equipoIds !== []) {
    $where .= ' AND EXISTS (SELECT 1 FROM viaje_operativo_asignaciones a2 WHERE a2.viaje_id = v.id AND a2.equipo_id IN ('
        . implode(',', array_fill(0, count($equipoIds), '?')) . '))';
    $binds = array_merge($binds, $equipoIds);
}
if ($numero !== null) {
    $where .= ' AND v.numero = ?';
    $binds[] = $numero;
}
$where .= ' ORDER BY v.id DESC LIMIT 25';

$viajes = $db->query(
    'SELECT ' . implode(', ', columnas($db, 'viajes_operativos', [
        'v.id', 'v.numero', 'v.estado', 'v.sucursal_id', 'v.fecha_salida_planificada', 'v.fecha_regreso_planificada',
        'v.fecha_salida_real', 'v.fecha_regreso_real', 'v.km_salida', 'v.km_regreso', 'v.observaciones',
        'v.created_at', 'v.updated_at',
    ])) . ' FROM viajes_operativos v WHERE ' . $where,
    $binds
)->getResultArray();

$detalle = [];
foreach ($viajes as $viaje) {
    $viajeId = (int) $viaje['id'];

    $asignaciones = $db->table('viaje_operativo_asignaciones a')
        ->select(implode(', ', columnas($db, 'viaje_operativo_asignaciones', [
            'a.id', 'a.rol', 'a.desde', 'a.hasta', 'a.equipo_id', 'a.empleado_id', 'a.porcentaje_liquidacion',
            'a.motivo', 'a.created_at',
        ])))
        ->select('e.codigo AS equipo_codigo, e.patente AS equipo_patente, '
            . 'emp.nombre AS empleado_nombre, emp.apellido AS empleado_apellido, emp.activo AS empleado_activo')
        ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'left')
        ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'left')
        ->where('a.empresa_id', $empresaId)
        ->where('a.viaje_id', $viajeId)
        ->orderBy('a.id', 'ASC')
        ->get()->getResultArray();

    $choferes = array_values(array_filter($asignaciones, static fn (array $row): bool => ($row['rol'] ?? '') === 'CHOFER'));

    // Porcentajes del maestro vigentes a la fecha de salida del viaje.
    $fecha = substr((string) ($viaje['fecha_salida_real'] ?? $viaje['fecha_salida_planificada'] ?? ''), 0, 10);
    $choferIds = array_values(array_filter(array_map(
        static fn (array $row): ?int => $row['empleado_id'] === null ? null : (int) $row['empleado_id'],
        $choferes,
    )));
    $maestro = [];
    if ($choferIds !== [] && $fecha !== '') {
        $maestro = $db->table('viajes_porcentajes_liquidacion_chofer p')
            ->select(implode(', ', columnas($db, 'viajes_porcentajes_liquidacion_chofer', [
                'p.id', 'p.empleado_id', 'p.porcentaje', 'p.vigente_desde', 'p.vigente_hasta', 'p.activo',
            ])))
            ->select('emp.nombre AS empleado_nombre, emp.apellido AS empleado_apellido')
            ->join('empleados emp', 'emp.id = p.empleado_id AND emp.empresa_id = p.empresa_id', 'left')
            ->where('p.empresa_id', $empresaId)
            ->whereIn('p.empleado_id', $choferIds)
            ->orderBy('p.vigente_desde', 'DESC')
            ->get()->getResultArray();
    }

    $tramos = $db->table('viaje_operativo_tramos t')
        ->select(implode(', ', columnas($db, 'viaje_operativo_tramos', [
            't.id', 't.orden', 't.origen', 't.destino', 't.km', 't.toneladas', 't.moneda', 't.cotizacion',
            't.moneda_liquidacion', 't.cotizacion_liquidacion', 't.modo_liquidacion',
            't.toneladas_liquidacion', 't.precio_unitario_liquidacion',
            't.flete_facturacion', 't.flete_facturacion_ars', 't.flete_liquidacion', 't.flete_liquidacion_ars',
            't.fecha_inicio', 't.fecha_fin', 't.anulado_at', 't.anulado_por', 't.motivo_anulacion', 't.observaciones',
        ])))
        ->select('cl.nombre AS cliente, cl.origen AS cliente_origen, cl.codigo_origen AS cliente_codigo')
        ->join('clientes cl', 'cl.id = t.cliente_id AND cl.empresa_id = t.empresa_id', 'left')
        ->where('t.empresa_id', $empresaId)
        ->where('t.viaje_id', $viajeId)
        ->orderBy('t.orden', 'ASC')
        ->get()->getResultArray();

    // Combustible y gastos del viaje, para saber si hay costos sin imputar.
    $combustible = $db->table('viaje_operativo_combustible')
        ->select(implode(', ', columnas($db, 'viaje_operativo_combustible', [
            'id', 'tramo_id', 'litros', 'precio_unitario', 'importe_ars', 'kilometraje', 'fecha', 'estacion',
        ])))
        ->where('empresa_id', $empresaId)
        ->where('viaje_id', $viajeId)
        ->get()->getResultArray();
    $gastos = $db->table('viaje_operativo_gastos')
        ->select(implode(', ', columnas($db, 'viaje_operativo_gastos', [
            'id', 'tramo_id', 'concepto', 'importe_ars', 'pagado_por', 'pagado_de_caja', 'fecha',
        ])))
        ->where('empresa_id', $empresaId)
        ->where('viaje_id', $viajeId)
        ->get()->getResultArray();

    // Columnas reales de las tablas de costos: para ver la descripcion del
    // gasto y saber si hay campos que la sonda no esta trayendo.
    $esquemaCostos = [
        'viaje_operativo_gastos' => $db->getFieldNames('viaje_operativo_gastos'),
        'viaje_operativo_combustible' => $db->getFieldNames('viaje_operativo_combustible'),
    ];
    $gastosCrudos = $db->table('viaje_operativo_gastos')
        ->where('empresa_id', $empresaId)
        ->where('viaje_id', $viajeId)
        ->get()->getResultArray();

    // Calculo replicando App\Application\Trips\GetOperationalTrip::financials().
    $porcentaje = null;
    $origenPorcentaje = null;
    if (count($choferes) === 1) {
        $asignado = $choferes[0]['porcentaje_liquidacion'] ?? null;
        $vigente = null;
        foreach ($maestro as $fila) {
            if ((int) ($fila['empleado_id'] ?? 0) !== (int) ($choferes[0]['empleado_id'] ?? 0)) {
                continue;
            }
            if ((int) ($fila['activo'] ?? 0) !== 1) {
                continue;
            }
            if ($fecha === '' || substr((string) $fila['vigente_desde'], 0, 10) > $fecha) {
                continue;
            }
            $hasta = $fila['vigente_hasta'] ?? null;
            if ($hasta !== null && substr((string) $hasta, 0, 10) < $fecha) {
                continue;
            }
            $vigente = $fila;
            break;
        }
        if ($asignado !== null) {
            $porcentaje = (float) $asignado;
            $origenPorcentaje = 'VIAJE';
        } elseif ($vigente !== null) {
            $porcentaje = (float) $vigente['porcentaje'];
            $origenPorcentaje = 'MAESTRO';
        }
    }

    $tramosCalculados = [];
    foreach ($tramos as $tramo) {
        $anulado = $tramo['anulado_at'] !== null;
        $liquidacion = $tramo['flete_liquidacion_ars'] === null ? null : (float) $tramo['flete_liquidacion_ars'];
        $facturacion = $tramo['flete_facturacion_ars'] === null ? null : (float) $tramo['flete_facturacion_ars'];
        $comision = ($porcentaje === null || $liquidacion === null || $anulado) ? null : round($liquidacion * $porcentaje / 100, 2);
        $costos = 0.0;
        foreach ($combustible as $mov) {
            if ((int) ($mov['tramo_id'] ?? 0) === (int) $tramo['id']) {
                $costos += (float) ($mov['importe_ars'] ?? 0);
            }
        }
        foreach ($gastos as $mov) {
            if ((int) ($mov['tramo_id'] ?? 0) === (int) $tramo['id']) {
                $costos += (float) ($mov['importe_ars'] ?? 0);
            }
        }
        $margen = ($comision === null || $facturacion === null) ? null : round($facturacion - $costos - $comision, 2);
        $tramosCalculados[] = [
            'tramo' => $tramo,
            'costos_asignados_ars' => round($costos, 2),
            'comision_ars' => $comision,
            'margen_ars' => $margen,
        ];
    }

    $detalle[] = [
        'viaje' => $viaje,
        'asignaciones' => $asignaciones,
        'choferes' => count($choferes),
        'porcentaje_calculado' => $porcentaje,
        'porcentaje_origen' => $origenPorcentaje,
        'porcentajes_maestro' => $maestro,
        'fecha_usada_para_vigencia' => $fecha,
        'tramos_calculados' => $tramosCalculados,
        'combustible' => $combustible,
        'gastos' => $gastos,
        'gastos_crudos' => $gastosCrudos,
        'esquema_costos' => $esquemaCostos,
    ];
}

$sucursales = $db->table('sucursales')->select('id, codigo, nombre')->where('empresa_id', $empresaId)->get()->getResultArray();
$porSucursal = [];
foreach ($sucursales as $sucursal) {
    $porSucursal[(int) $sucursal['id']] = $sucursal['codigo'] . ' - ' . $sucursal['nombre'];
}

// Detalle de cuando se creo cada porcentaje del maestro: sirve para
// entender por que un chofer nuevo no tiene porcentaje vigente.
$maestroCompleto = $db->table('viajes_porcentajes_liquidacion_chofer p')
    ->select(implode(', ', columnas($db, 'viajes_porcentajes_liquidacion_chofer', [
        'p.id', 'p.empleado_id', 'p.porcentaje', 'p.vigente_desde', 'p.vigente_hasta', 'p.activo',
        'p.created_at', 'p.updated_at',
    ])))
    ->select('emp.nombre AS empleado_nombre, emp.apellido AS empleado_apellido')
    ->join('empleados emp', 'emp.id = p.empleado_id AND emp.empresa_id = p.empresa_id', 'left')
    ->where('p.empresa_id', $empresaId)
    ->orderBy('p.empleado_id', 'ASC')
    ->orderBy('p.vigente_desde', 'DESC')
    ->get()->getResultArray();

// Conteos de contexto para la empresa.
$conteos = [
    'empleados_activos' => $db->table('empleados')->where('empresa_id', $empresaId)->where('activo', 1)->countAllResults(),
    'porcentajes_maestro' => count($maestroCompleto),
    'viajes_operativos' => $db->table('viajes_operativos')->where('empresa_id', $empresaId)->countAllResults(),
    'viajes_con_porcentaje' => $db->table('viaje_operativo_asignaciones')
        ->where('empresa_id', $empresaId)
        ->where('rol', 'CHOFER')
        ->where('porcentaje_liquidacion IS NOT NULL')
        ->countAllResults(),
];

// Empleados activos de la empresa sin fila en el maestro de porcentajes.
$empleados = $db->table('empleados')
    ->select('id, nombre, apellido, activo, created_at')
    ->where('empresa_id', $empresaId)
    ->get()->getResultArray();
$conMaestro = [];
foreach ($maestroCompleto as $fila) {
    $conMaestro[(int) $fila['empleado_id']] = true;
}
$sinMaestro = [];
foreach ($empleados as $empleado) {
    if (isset($conMaestro[(int) $empleado['id']])) {
        continue;
    }
    $sinMaestro[] = $empleado;
}

// Viajes operativos con chofer asignado que quedaron sin porcentaje.
$viajesSinPorcentaje = $db->query(
    'SELECT v.id, v.numero, v.estado, v.fecha_salida_planificada, v.fecha_salida_real, '
    . 'a.empleado_id, emp.nombre AS chofer_nombre, emp.apellido AS chofer_apellido '
    . 'FROM viajes_operativos v '
    . 'JOIN viaje_operativo_asignaciones a ON a.viaje_id = v.id AND a.empresa_id = v.empresa_id AND a.rol = ? '
    . 'LEFT JOIN empleados emp ON emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id '
    . 'WHERE v.empresa_id = ? AND a.porcentaje_liquidacion IS NULL '
    . 'ORDER BY v.numero ASC',
    ['CHOFER', $empresaId]
)->getResultArray();

// Analisis global: para cada viaje operativo con chofer, que porcentaje
// resuelve la app y que por que no.
$maestroPorEmpleado = [];
foreach ($maestroCompleto as $fila) {
    $maestroPorEmpleado[(int) $fila['empleado_id']][] = $fila;
}

$analisis = $db->query(
    'SELECT v.id, v.numero, v.estado, v.empresa_id, v.created_at, '
    . 'COALESCE(v.fecha_salida_real, v.fecha_salida_planificada) AS salida, '
    . 'a.empleado_id, a.porcentaje_liquidacion, '
    . 'emp.nombre AS chofer_nombre, emp.apellido AS chofer_apellido, emp.created_at AS chofer_alta '
    . 'FROM viajes_operativos v '
    . 'JOIN viaje_operativo_asignaciones a ON a.viaje_id = v.id AND a.empresa_id = v.empresa_id AND a.rol = ? '
    . 'LEFT JOIN empleados emp ON emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id '
    . 'WHERE v.empresa_id = ? ORDER BY v.numero ASC',
    ['CHOFER', $empresaId]
)->getResultArray();

$resumenGlobal = [
    'viajes_con_chofer' => count($analisis),
    'con_porcentaje_resuelto' => 0,
    'sin_porcentaje_maestro' => 0,
    'maestro_no_vigente_por_fecha' => 0,
    'por_porcentaje_en_asignacion' => 0,
    'detalle_sin_porcentaje' => [],
];

foreach ($analisis as $fila) {
    $empleadoId = (int) ($fila['empleado_id'] ?? 0);
    $fecha = substr((string) ($fila['salida'] ?? ''), 0, 10);
    $filasMaestro = $maestroPorEmpleado[$empleadoId] ?? [];

    if ($fila['porcentaje_liquidacion'] !== null) {
        $resumenGlobal['con_porcentaje_resuelto']++;
        $resumenGlobal['por_porcentaje_en_asignacion']++;
        continue;
    }
    if ($filasMaestro === []) {
        $resumenGlobal['sin_porcentaje_maestro']++;
        $resumenGlobal['detalle_sin_porcentaje'][] = [
            'viaje_id' => (int) $fila['id'],
            'numero' => (int) $fila['numero'],
            'estado' => $fila['estado'],
            'viaje_created_at' => $fila['created_at'] ?? null,
            'salida' => $fila['salida'],
            'empleado_id' => $empleadoId,
            'chofer' => trim((string) ($fila['chofer_nombre'] ?? '') . ' ' . (string) ($fila['chofer_apellido'] ?? '')),
            'chofer_alta' => $fila['chofer_alta'] ?? null,
            'motivo' => 'SIN_FILA_EN_MAESTRO',
        ];
        continue;
    }
    // Tiene maestro, pero ninguna fila vigente a la fecha de salida.
    $vigente = null;
    foreach ($filasMaestro as $maestro) {
        if ((int) ($maestro['activo'] ?? 0) !== 1) {
            continue;
        }
        if ($fecha === '' || substr((string) $maestro['vigente_desde'], 0, 10) > $fecha) {
            continue;
        }
        $hasta = $maestro['vigente_hasta'] ?? null;
        if ($hasta !== null && substr((string) $hasta, 0, 10) < $fecha) {
            continue;
        }
        $vigente = $maestro;
        break;
    }
    if ($vigente !== null) {
        $resumenGlobal['con_porcentaje_resuelto']++;
        continue;
    }
    $resumenGlobal['maestro_no_vigente_por_fecha']++;
    $resumenGlobal['detalle_sin_porcentaje'][] = [
        'viaje_id' => (int) $fila['id'],
        'numero' => (int) $fila['numero'],
        'estado' => $fila['estado'],
        'viaje_created_at' => $fila['created_at'] ?? null,
        'salida' => $fila['salida'],
        'empleado_id' => $empleadoId,
        'chofer' => trim((string) ($fila['chofer_nombre'] ?? '') . ' ' . (string) ($fila['chofer_apellido'] ?? '')),
        'chofer_alta' => $fila['chofer_alta'] ?? null,
        'motivo' => 'MAESTRO_NO_VIGENTE_EN_FECHA_DE_SALIDA',
        'maestro_disponible' => $filasMaestro,
    ];
}

// Distribucion de porcentajes ya fijados en los viajes: sirve para saber que
// valor usa la operacion de TSA.
$distribucion = $db->query(
    'SELECT a.porcentaje_liquidacion, COUNT(*) AS cantidad '
    . 'FROM viaje_operativo_asignaciones a '
    . 'WHERE a.empresa_id = ? AND a.rol = ? AND a.porcentaje_liquidacion IS NOT NULL '
    . 'GROUP BY a.porcentaje_liquidacion ORDER BY cantidad DESC',
    [$empresaId, 'CHOFER']
)->getResultArray();

// Tramos con flete de facturacion cargado pero sin flete de liquidacion:
// son los que quedan con comision y margen en "Sin datos" aunque el chofer
// tenga porcentaje definido (la app bloquea el cierre del viaje).
$tramosSinLiquidacion = $db->query(
    'SELECT v.id AS viaje_id, v.numero, v.estado, '
    . 't.id AS tramo_id, t.orden, t.origen, t.destino, t.moneda, t.cotizacion, '
    . 't.moneda_liquidacion, t.cotizacion_liquidacion, '
    . 't.flete_facturacion, t.flete_facturacion_ars '
    . 'FROM viaje_operativo_tramos t '
    . 'JOIN viajes_operativos v ON v.id = t.viaje_id AND v.empresa_id = t.empresa_id '
    . 'WHERE t.empresa_id = ? AND t.anulado_at IS NULL '
    . 'AND t.flete_liquidacion_ars IS NULL AND t.flete_facturacion_ars IS NOT NULL '
    . 'ORDER BY v.numero ASC, t.orden ASC',
    [$empresaId]
)->getResultArray();

$viajesConTramosIncompletos = [];
foreach ($tramosSinLiquidacion as $tramo) {
    $viajeId = (int) $tramo['viaje_id'];
    if (! isset($viajesConTramosIncompletos[$viajeId])) {
        $viajesConTramosIncompletos[$viajeId] = [
            'viaje_id' => $viajeId,
            'numero' => (int) $tramo['numero'],
            'estado' => $tramo['estado'],
            'tramos_sin_liquidacion' => [],
        ];
    }
    $viajesConTramosIncompletos[$viajeId]['tramos_sin_liquidacion'][] = [
        'tramo_id' => (int) $tramo['tramo_id'],
        'orden' => (int) $tramo['orden'],
        'origen' => $tramo['origen'],
        'destino' => $tramo['destino'],
        'moneda' => $tramo['moneda'],
        'cotizacion' => $tramo['cotizacion'] === null ? null : (float) $tramo['cotizacion'],
        'moneda_liquidacion' => $tramo['moneda_liquidacion'],
        'cotizacion_liquidacion' => $tramo['cotizacion_liquidacion'] === null ? null : (float) $tramo['cotizacion_liquidacion'],
        'flete_facturacion' => $tramo['flete_facturacion'] === null ? null : (float) $tramo['flete_facturacion'],
        'flete_facturacion_ars' => $tramo['flete_facturacion_ars'] === null ? null : (float) $tramo['flete_facturacion_ars'],
    ];
}

// Tramos con los dos fletes cargados: sirve para ver si la liquidacion al
// chofer coincide con la facturacion al cliente (dato que define si completar
// los tramos faltantes por convencion seria razonable).
$tramosConAmbos = $db->query(
    'SELECT v.numero, t.orden, t.origen, t.destino, '
    . 't.moneda, t.moneda_liquidacion, '
    . 't.flete_facturacion_ars, t.flete_liquidacion_ars '
    . 'FROM viaje_operativo_tramos t '
    . 'JOIN viajes_operativos v ON v.id = t.viaje_id AND v.empresa_id = t.empresa_id '
    . 'WHERE t.empresa_id = ? AND t.anulado_at IS NULL '
    . 'AND t.flete_liquidacion_ars IS NOT NULL AND t.flete_facturacion_ars IS NOT NULL '
    . 'ORDER BY v.numero ASC, t.orden ASC',
    [$empresaId]
)->getResultArray();

$iguales = 0;
$distintos = [];
foreach ($tramosConAmbos as $tramo) {
    $facturacion = (float) $tramo['flete_facturacion_ars'];
    $liquidacion = (float) $tramo['flete_liquidacion_ars'];
    if (abs($facturacion - $liquidacion) < 0.01) {
        $iguales++;
        continue;
    }
    $distintos[] = [
        'viaje' => (int) $tramo['numero'],
        'tramo' => (int) $tramo['orden'],
        'origen' => $tramo['origen'],
        'destino' => $tramo['destino'],
        'moneda' => $tramo['moneda'],
        'moneda_liquidacion' => $tramo['moneda_liquidacion'],
        'facturacion_ars' => $facturacion,
        'liquidacion_ars' => $liquidacion,
        'diferencia_ars' => round($liquidacion - $facturacion, 2),
    ];
}

$resumenLiquidacion = [
    'tramos_con_facturacion_y_sin_liquidacion' => count($tramosSinLiquidacion),
    'viajes_afectados' => count($viajesConTramosIncompletos),
    'detalle' => array_values($viajesConTramosIncompletos),
    'tramos_con_ambos_fletes' => count($tramosConAmbos),
    'tramos_donde_liquidacion_iguala_facturacion' => $iguales,
    'tramos_donde_difieren' => count($distintos),
    'detalle_donde_difieren' => $distintos,
];

// Choferes reales de la empresa (los que aparecen como CHOFER en algun viaje
// operativo) que no tienen ninguna fila en el maestro de porcentajes: sin esa
// fila, un viaje nuevo puede quedar sin porcentaje.
$choferesSinFila = $db->query(
    'SELECT DISTINCT a.empleado_id, emp.nombre, emp.apellido, emp.activo, emp.created_at '
    . 'FROM viaje_operativo_asignaciones a '
    . 'JOIN empleados emp ON emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id '
    . 'LEFT JOIN viajes_porcentajes_liquidacion_chofer p '
    . 'ON p.empresa_id = a.empresa_id AND p.empleado_id = a.empleado_id '
    . 'WHERE a.empresa_id = ? AND a.rol = ? AND a.empleado_id IS NOT NULL AND p.id IS NULL '
    . 'ORDER BY emp.nombre ASC',
    [$empresaId, 'CHOFER']
)->getResultArray();

$salida = [
    'ok' => true,
    'empresa_id' => $empresaId,
    'empresa' => $empresaNombre,
    'patente_buscada' => $patente,
    'numero_buscado' => $numero,
    'equipos' => $equipos,
    'sucursales' => $porSucursal,
    'conteos' => $conteos,
    'distribucion_porcentajes_operacion' => $distribucion,
    'analisis_global' => $resumenGlobal,
    'tramos_sin_liquidacion' => $resumenLiquidacion,
    'choferes_sin_fila_en_maestro' => $choferesSinFila,
    'maestro_completo' => $maestroCompleto,
    'empleados_sin_porcentaje' => $sinMaestro,
    'viajes_sin_porcentaje_en_asignacion' => $viajesSinPorcentaje,
    'viajes_encontrados' => count($detalle),
    'viajes' => $detalle,
];

echo $format === 'json'
    ? json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    : print_r($salida, true);
