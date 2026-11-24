<?php

declare(strict_types=1);

/**
 * Auditoria de SOLO LECTURA de los viajes operativos en produccion Ferozo.
 *
 * Responde tres preguntas con datos reales:
 *   1. Hay combustible cargado? Cuanto, a que precio y con que imputacion.
 *   2. Como estan los viajes: estado, antiguedad, tramos completos, porcentaje
 *      de liquidacion resuelto y lectura de regreso.
 *   3. Como cierra la economia de cada viaje: facturado, comision, costos,
 *      otros haberes y margen (mismo calculo que GetOperationalTrip::financials).
 *
 * NO escribe nada: todas las consultas son SELECT.
 *
 * Uso en produccion Ferozo (sin SSH ni CLI):
 *   1. Subir este archivo a la raiz del release por FTPS.
 *   2. Llamarlo con el header `X-Migrate-Token` (valor de MIGRATE_TOKEN del .env
 *      de produccion):
 *        curl -H "X-Migrate-Token: <token>" \
 *             "https://vogelconsultoria.com.ar/mantenimiento/auditoria-produccion-viajes.php"
 *      Parametros opcionales:
 *        empresa_id=N    Por defecto: detecta TSA.
 *        viaje=N         Limita el detalle a un numero de viaje.
 *        format=json     (default json)
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
    if ($isCli) {
        fwrite(STDERR, "ERROR: no se encontro .env legible en la raiz del release.\n");
        exit(1);
    }
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

// Los avisos de PHP no deben romper el JSON: se capturan y se devuelven
// dentro del informe.
$avisosPhp = [];
set_error_handler(static function (int $severidad, string $mensaje, string $archivo = '', int $linea = 0) use (&$avisosPhp): bool {
    $avisosPhp[] = $mensaje . ' (' . basename((string) $archivo) . ':' . $linea . ')';

    return true;
});

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
 * Columnas reales de una tabla (introspeccion). Cache por tabla.
 *
 * @return list<string>
 */
function camposDe($db, string $tabla): array
{
    static $cache = [];
    if (! array_key_exists($tabla, $cache)) {
        try {
            $campos = $db->getFieldNames($tabla);
            $cache[$tabla] = is_array($campos) ? array_values($campos) : [];
        } catch (Throwable) {
            $cache[$tabla] = [];
        }
    }

    return $cache[$tabla];
}

function existeTabla($db, string $tabla): bool
{
    return camposDe($db, $tabla) !== [];
}

function tieneColumna($db, string $tabla, string $columna): bool
{
    return in_array($columna, camposDe($db, $tabla), true);
}

/**
 * Filtra la lista de columnas contra el esquema real desplegado.
 *
 * @param list<string> $fields
 * @return list<string>
 */
function cols($db, string $tabla, array $fields): array
{
    $reales = camposDe($db, $tabla);
    if ($reales === []) {
        return [];
    }

    return array_values(array_filter($fields, static fn (string $field): bool => in_array($field, $reales, true)));
}

function num(mixed $valor): float
{
    return $valor === null ? 0.0 : (float) $valor;
}

function fecha(mixed $valor): ?string
{
    if ($valor === null) {
        return null;
    }
    $texto = substr((string) $valor, 0, 10);

    return $texto === '' ? null : $texto;
}

// --- Empresa objetivo (por defecto, la que tiene mas equipos: TSA). ---
$empresaId = isset($_GET['empresa_id']) && $_GET['empresa_id'] !== '' ? (int) $_GET['empresa_id'] : null;
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
        echo json_encode(['error' => 'No se pudo identificar la empresa.'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    $empresaId = (int) $mejor['id'];
    $empresaNombre = (string) ($mejor['razon_social'] ?? $mejor['nombre_fantasia'] ?? '');
} else {
    $fila = $db->table('empresas')->select('razon_social, nombre_fantasia')->where('id', $empresaId)->get()->getRowArray();
    $empresaNombre = (string) ($fila['razon_social'] ?? $fila['nombre_fantasia'] ?? '');
}

// --- Viajes ---
$viajes = [];
if (existeTabla($db, 'viajes_operativos')) {
    $sel = cols($db, 'viajes_operativos', [
        'id', 'numero', 'estado', 'sucursal_id', 'fecha_salida_planificada', 'fecha_regreso_planificada',
        'fecha_salida_real', 'fecha_regreso_real', 'km_salida', 'km_regreso', 'observaciones',
        'motivo_cancelacion', 'created_at',
    ]);
    if ($sel !== []) {
        $viajes = $db->table('viajes_operativos')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId)
            ->orderBy('numero', 'ASC')
            ->get()->getResultArray();
    }
}

$viajeId = isset($_GET['viaje']) && $_GET['viaje'] !== '' ? (int) $_GET['viaje'] : null;
if ($viajeId !== null) {
    $viajes = array_values(array_filter($viajes, static fn (array $v): bool => (int) $v['id'] === $viajeId));
}

// --- Equipos y empleados (mapas para no repetir joins) ---
$equiposPorId = [];
foreach ($db->table('equipos')->select('id, codigo, patente')->where('empresa_id', $empresaId)->get()->getResultArray() as $equipo) {
    $equiposPorId[(int) $equipo['id']] = trim((string) ($equipo['codigo'] ?? '') . ' ' . (string) ($equipo['patente'] ?? ''));
}

$empleadosPorId = [];
foreach ($db->table('empleados')->select('id, nombre, apellido, activo')->where('empresa_id', $empresaId)->get()->getResultArray() as $empleado) {
    $empleadosPorId[(int) $empleado['id']] = [
        'nombre' => trim((string) ($empleado['nombre'] ?? '') . ' ' . (string) ($empleado['apellido'] ?? '')),
        'activo' => (int) ($empleado['activo'] ?? 0),
    ];
}

// --- Asignaciones ---
$asignacionesPorViaje = [];
if (existeTabla($db, 'viaje_operativo_asignaciones')) {
    $sel = cols($db, 'viaje_operativo_asignaciones', ['id', 'viaje_id', 'rol', 'equipo_id', 'empleado_id', 'desde', 'hasta', 'porcentaje_liquidacion', 'motivo']);
    if ($sel !== []) {
        foreach ($db->table('viaje_operativo_asignaciones')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId)
            ->get()->getResultArray() as $asignacion) {
            $asignacionesPorViaje[(int) $asignacion['viaje_id']][] = $asignacion;
        }
    }
}

// --- Maestro de porcentajes ---
$maestroPorEmpleado = [];
if (existeTabla($db, 'viajes_porcentajes_liquidacion_chofer')) {
    $sel = cols($db, 'viajes_porcentajes_liquidacion_chofer', ['id', 'empleado_id', 'porcentaje', 'vigente_desde', 'vigente_hasta', 'activo']);
    if ($sel !== []) {
        foreach ($db->table('viajes_porcentajes_liquidacion_chofer')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId)
            ->orderBy('vigente_desde', 'DESC')
            ->get()->getResultArray() as $fila) {
            $maestroPorEmpleado[(int) $fila['empleado_id']][] = $fila;
        }
    }
}

// --- Porcentaje por defecto de la empresa ---
$defaultEmpresa = null;
$defaultEmpresaId = null;
if (existeTabla($db, 'viajes_porcentaje_liquidacion_defecto')) {
    $sel = cols($db, 'viajes_porcentaje_liquidacion_defecto', ['id', 'porcentaje']);
    if ($sel !== []) {
        $fila = $db->table('viajes_porcentaje_liquidacion_defecto')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId)
            ->get()->getRowArray();
        if ($fila !== null) {
            $defaultEmpresa = num($fila['porcentaje']);
            $defaultEmpresaId = (int) $fila['id'];
        }
    }
}

/**
 * Fila del maestro vigente a una fecha (la mas reciente que cubre el periodo).
 *
 * @param list<array<string,mixed>> $filas
 * @return array<string,mixed>|null
 */
function maestroVigente(array $filas, ?string $fecha): ?array
{
    if ($fecha === null) {
        return null;
    }
    $vigente = null;
    foreach ($filas as $fila) {
        if ((int) ($fila['activo'] ?? 0) !== 1) {
            continue;
        }
        $desde = fecha($fila['vigente_desde'] ?? null);
        if ($desde !== null && $desde > $fecha) {
            continue;
        }
        $hasta = fecha($fila['vigente_hasta'] ?? null);
        if ($hasta !== null && $hasta < $fecha) {
            continue;
        }
        $vigente = $fila;
        break;
    }

    return $vigente;
}

// --- Tramos ---
$tramosPorViaje = [];
$tramosAnulados = 0;
if (existeTabla($db, 'viaje_operativo_tramos')) {
    $sel = cols($db, 'viaje_operativo_tramos', [
        'id', 'viaje_id', 'orden', 'origen', 'destino', 'km', 'toneladas', 'moneda', 'cotizacion',
        'moneda_liquidacion', 'cotizacion_liquidacion', 'modo_liquidacion', 'toneladas_liquidacion',
        'precio_unitario_liquidacion', 'flete_facturacion', 'flete_facturacion_ars',
        'flete_liquidacion', 'flete_liquidacion_ars', 'anulado_at', 'motivo_anulacion',
    ]);
    if ($sel !== []) {
        foreach ($db->table('viaje_operativo_tramos')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId)
            ->orderBy('orden', 'ASC')
            ->get()->getResultArray() as $tramo) {
            if (($tramo['anulado_at'] ?? null) !== null) {
                $tramosAnulados++;
                continue;
            }
            $tramosPorViaje[(int) $tramo['viaje_id']][] = $tramo;
        }
    }
}

// --- Combustible ---
$combustiblePorViaje = [];
if (existeTabla($db, 'viaje_operativo_combustible')) {
    $sel = cols($db, 'viaje_operativo_combustible', [
        'id', 'viaje_id', 'tramo_id', 'fecha', 'litros', 'importe', 'moneda', 'cotizacion',
        'importe_ars', 'kilometraje', 'estacion', 'comprobante', 'observaciones',
    ]);
    if ($sel !== []) {
        $builder = $db->table('viaje_operativo_combustible')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId);
        if (tieneColumna($db, 'viaje_operativo_combustible', 'deleted_at')) {
            $builder->where('deleted_at', null);
        }
        foreach ($builder->orderBy('fecha', 'ASC')->get()->getResultArray() as $fila) {
            $combustiblePorViaje[(int) $fila['viaje_id']][] = $fila;
        }
    }
}

// --- Gastos ---
$gastosPorViaje = [];
if (existeTabla($db, 'viaje_operativo_gastos')) {
    $sel = cols($db, 'viaje_operativo_gastos', [
        'id', 'viaje_id', 'tramo_id', 'categoria_id', 'concepto_id', 'cantidad', 'importe_unitario',
        'importe', 'moneda', 'cotizacion', 'importe_ars', 'medio_pago_id', 'pagado_de_caja',
        'fecha', 'comprobante', 'observaciones',
    ]);
    if ($sel !== []) {
        $builder = $db->table('viaje_operativo_gastos')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId);
        if (tieneColumna($db, 'viaje_operativo_gastos', 'deleted_at')) {
            $builder->where('deleted_at', null);
        }
        foreach ($builder->orderBy('fecha', 'ASC')->get()->getResultArray() as $fila) {
            $gastosPorViaje[(int) $fila['viaje_id']][] = $fila;
        }
    }
}

// --- Otros haberes ---
$haberesPorViaje = [];
if (existeTabla($db, 'viaje_operativo_otros_haberes')) {
    $sel = cols($db, 'viaje_operativo_otros_haberes', [
        'id', 'viaje_id', 'empleado_id', 'fecha', 'concepto', 'importe', 'moneda', 'cotizacion', 'importe_ars',
    ]);
    if ($sel !== []) {
        $builder = $db->table('viaje_operativo_otros_haberes')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId);
        if (tieneColumna($db, 'viaje_operativo_otros_haberes', 'deleted_at')) {
            $builder->where('deleted_at', null);
        }
        foreach ($builder->orderBy('fecha', 'ASC')->get()->getResultArray() as $fila) {
            $haberesPorViaje[(int) $fila['viaje_id']][] = $fila;
        }
    }
}

// --- Caja ---
$cajaPorViaje = [];
if (existeTabla($db, 'viaje_operativo_caja_movimientos')) {
    $sel = cols($db, 'viaje_operativo_caja_movimientos', [
        'id', 'viaje_id', 'tipo', 'fecha', 'importe', 'moneda', 'cotizacion', 'importe_ars', 'observaciones',
    ]);
    if ($sel !== []) {
        $builder = $db->table('viaje_operativo_caja_movimientos')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId);
        if (tieneColumna($db, 'viaje_operativo_caja_movimientos', 'deleted_at')) {
            $builder->where('deleted_at', null);
        }
        foreach ($builder->orderBy('fecha', 'ASC')->get()->getResultArray() as $fila) {
            $cajaPorViaje[(int) $fila['viaje_id']][] = $fila;
        }
    }
}

// --- Lecturas de equipo con origen VIAJE ---
$lecturasPorViaje = [];
if (existeTabla($db, 'lecturas_equipo')) {
    $sel = cols($db, 'lecturas_equipo', ['id', 'equipo_id', 'fecha_lectura', 'kilometraje', 'origen', 'referencia_origen', 'anulada']);
    if ($sel !== []) {
        $builder = $db->table('lecturas_equipo')
            ->select(implode(', ', $sel))
            ->where('empresa_id', $empresaId)
            ->where('origen', 'VIAJE');
        if (tieneColumna($db, 'lecturas_equipo', 'anulada')) {
            $builder->where('anulada', 0);
        }
        foreach ($builder->get()->getResultArray() as $fila) {
            $referencia = (string) ($fila['referencia_origen'] ?? '');
            if (preg_match('/Viaje #(\d+)/', $referencia, $m)) {
                $lecturasPorViaje[(int) $m[1]][] = $fila;
            }
        }
    }
}

// --- Catalogo de categorias de gasto (para nombrar los gastos) ---
$categoriasPorId = [];
if (existeTabla($db, 'viajes_categorias_gasto')) {
    foreach ($db->table('viajes_categorias_gasto')->select('id, nombre')->get()->getResultArray() as $categoria) {
        $categoriasPorId[(int) $categoria['id']] = (string) ($categoria['nombre'] ?? '');
    }
}

// ============================================================================
// Calculo por viaje (mismo criterio que GetOperationalTrip::financials).
// ============================================================================
$hoy = date('Y-m-d');
$detalle = [];
$alertas = [];
// Tramos vivos por viaje: lo usan los agregados para saber si un costo esta
// imputado a un tramo real o quedo sin imputar.
$legIdsPorViaje = [];

foreach ($viajes as $viaje) {
    $id = (int) $viaje['id'];
    $numero = (int) $viaje['numero'];
    $estado = (string) $viaje['estado'];
    $salida = fecha($viaje['fecha_salida_real'] ?? null) ?? fecha($viaje['fecha_salida_planificada'] ?? null);
    $regreso = fecha($viaje['fecha_regreso_real'] ?? null) ?? fecha($viaje['fecha_regreso_planificada'] ?? null);

    $asignaciones = $asignacionesPorViaje[$id] ?? [];
    $choferes = array_values(array_filter($asignaciones, static fn (array $a): bool => ($a['rol'] ?? '') === 'CHOFER'));
    $equipoId = null;
    foreach ($asignaciones as $asignacion) {
        if (($asignacion['rol'] ?? '') === 'EQUIPO' && ($asignacion['equipo_id'] ?? null) !== null) {
            $equipoId = (int) $asignacion['equipo_id'];
        }
    }
    if ($equipoId === null) {
        foreach ($asignaciones as $asignacion) {
            if (($asignacion['equipo_id'] ?? null) !== null) {
                $equipoId = (int) $asignacion['equipo_id'];
            }
        }
    }

    // --- Porcentaje de liquidacion: VIAJE -> MAESTRO -> EMPRESA ---
    $porcentaje = null;
    $origenPorcentaje = null;
    $maestroUsado = null;
    if (count($choferes) === 1) {
        $empleadoId = (int) ($choferes[0]['empleado_id'] ?? 0);
        $enViaje = $choferes[0]['porcentaje_liquidacion'] ?? null;
        $vigente = maestroVigente($maestroPorEmpleado[$empleadoId] ?? [], $salida);
        if ($enViaje !== null) {
            $porcentaje = round(num($enViaje), 2);
            $origenPorcentaje = 'VIAJE';
        } elseif ($vigente !== null) {
            $porcentaje = round(num($vigente['porcentaje']), 2);
            $origenPorcentaje = 'MAESTRO';
            $maestroUsado = $vigente;
        } elseif ($defaultEmpresa !== null) {
            $porcentaje = round($defaultEmpresa, 2);
            $origenPorcentaje = 'EMPRESA';
        }
    } elseif (count($choferes) > 1) {
        $origenPorcentaje = 'MULTIPLES_CHOFERES';
    } else {
        $origenPorcentaje = 'SIN_CHOFER';
    }

    // --- Tramos ---
    $tramos = $tramosPorViaje[$id] ?? [];
    $legIds = [];
    $facturacion = 0.0;
    $liquidacion = 0.0;
    $facturacionCompleta = true;
    $liquidacionCompleta = true;
    $tramosSinImporte = [];
    $kmTramos = 0.0;
    foreach ($tramos as $tramo) {
        $legIds[(int) $tramo['id']] = true;
        $kmTramos += num($tramo['km'] ?? null);
        $fact = $tramo['flete_facturacion_ars'] ?? null;
        $liq = $tramo['flete_liquidacion_ars'] ?? null;
        if ($fact === null) {
            $facturacionCompleta = false;
        } else {
            $facturacion += num($fact);
        }
        if ($liq === null) {
            $liquidacionCompleta = false;
        } else {
            $liquidacion += num($liq);
        }
        if ($fact === null || $liq === null) {
            $tramosSinImporte[] = (int) $tramo['orden'];
        }
    }

    // --- Combustible ---
    $legIdsPorViaje[$id] = $legIds;
    $combustible = $combustiblePorViaje[$id] ?? [];
    $litros = 0.0;
    $importeCombustible = 0.0;
    $combustibleImputado = 0.0;
    $combustibleSinImputar = 0.0;
    foreach ($combustible as $fila) {
        $litros += num($fila['litros'] ?? null);
        $importeCombustible += num($fila['importe_ars'] ?? null);
        $tramoId = $fila['tramo_id'] ?? null;
        if ($tramoId !== null && isset($legIds[(int) $tramoId])) {
            $combustibleImputado += num($fila['importe_ars'] ?? null);
        } else {
            $combustibleSinImputar += num($fila['importe_ars'] ?? null);
        }
    }

    // --- Gastos ---
    $gastos = $gastosPorViaje[$id] ?? [];
    $importeGastos = 0.0;
    $gastosImputados = 0.0;
    $gastosSinImputar = 0.0;
    $gastosDeCaja = 0.0;
    foreach ($gastos as $fila) {
        $importeGastos += num($fila['importe_ars'] ?? null);
        $tramoId = $fila['tramo_id'] ?? null;
        if ($tramoId !== null && isset($legIds[(int) $tramoId])) {
            $gastosImputados += num($fila['importe_ars'] ?? null);
        } else {
            $gastosSinImputar += num($fila['importe_ars'] ?? null);
        }
        if ((int) ($fila['pagado_de_caja'] ?? 0) === 1) {
            $gastosDeCaja += num($fila['importe_ars'] ?? null);
        }
    }

    // --- Otros haberes (solo los del chofer responsable, como en la app) ---
    $haberes = $haberesPorViaje[$id] ?? [];
    $empleadoChofer = count($choferes) === 1 ? (int) ($choferes[0]['empleado_id'] ?? 0) : null;
    $otrosHaberes = 0.0;
    foreach ($haberes as $fila) {
        if ($empleadoChofer !== null && (int) ($fila['empleado_id'] ?? 0) !== $empleadoChofer) {
            continue;
        }
        $otrosHaberes += num($fila['importe_ars'] ?? null);
    }

    // --- Caja ---
    $caja = $cajaPorViaje[$id] ?? [];
    $cajaTotal = 0.0;
    $cajaPorTipo = [];
    foreach ($caja as $fila) {
        $cajaTotal += num($fila['importe_ars'] ?? null);
        $tipo = (string) ($fila['tipo'] ?? 'SIN_TIPO');
        $cajaPorTipo[$tipo] = ($cajaPorTipo[$tipo] ?? 0.0) + num($fila['importe_ars'] ?? null);
    }

    // --- Comision y margen (mismo calculo que la app) ---
    $comision = ($porcentaje === null || ! $liquidacionCompleta) ? null : round($liquidacion * $porcentaje / 100, 2);
    $costosTotales = round($importeCombustible + $importeGastos, 2);
    $margen = ($comision === null || ! $facturacionCompleta || $tramos === []) ? null : round($facturacion - $costosTotales - $comision - $otrosHaberes, 2);
    $margenPct = ($margen === null || $facturacion <= 0) ? null : round($margen / $facturacion * 100, 1);

    // --- Kilometraje y consumo ---
    $kmSalida = $viaje['km_salida'] ?? null;
    $kmRegreso = $viaje['km_regreso'] ?? null;
    $kmRecorridos = null;
    if ($kmSalida !== null && $kmRegreso !== null && (int) $kmRegreso >= (int) $kmSalida) {
        $kmRecorridos = (int) $kmRegreso - (int) $kmSalida;
    } elseif ($kmTramos > 0) {
        $kmRecorridos = $kmTramos;
    }
    $consumo = ($kmRecorridos !== null && $kmRecorridos > 0 && $litros > 0)
        ? round($litros / $kmRecorridos * 100, 2)
        : null;

    // --- Lectura de regreso ---
    $lecturas = $lecturasPorViaje[$numero] ?? [];
    $tieneLecturaRegreso = false;
    $lecturaKm = null;
    foreach ($lecturas as $lectura) {
        $tieneLecturaRegreso = true;
        $lecturaKm = ($lectura['kilometraje'] ?? null) === null ? null : (int) $lectura['kilometraje'];
    }

    // --- Dias abiertos ---
    $diasAbierto = null;
    if ($estado === 'EN_CURSO' && $salida !== null) {
        $diasAbierto = (int) floor((strtotime($hoy) - strtotime($salida)) / 86400);
    }

    $detalle[] = [
        'viaje_id' => $id,
        'numero' => $numero,
        'estado' => $estado,
        'sucursal_id' => $viaje['sucursal_id'] ?? null,
        'equipo' => $equipoId === null ? null : ($equiposPorId[$equipoId] ?? ('equipo#' . $equipoId)),
        'equipo_id' => $equipoId,
        'chofer' => count($choferes) === 1
            ? ($empleadosPorId[(int) ($choferes[0]['empleado_id'] ?? 0)]['nombre'] ?? null)
            : null,
        'choferes_asignados' => count($choferes),
        'fecha_salida' => $salida,
        'fecha_regreso' => $regreso,
        'dias_abierto' => $diasAbierto,
        'km_salida' => $kmSalida === null ? null : (int) $kmSalida,
        'km_regreso' => $kmRegreso === null ? null : (int) $kmRegreso,
        'km_recorridos' => $kmRecorridos,
        'tramos' => count($tramos),
        'tramos_sin_importe' => $tramosSinImporte,
        'facturacion_ars' => $facturacionCompleta ? round($facturacion, 2) : null,
        'liquidacion_ars' => $liquidacionCompleta ? round($liquidacion, 2) : null,
        'porcentaje_liquidacion' => $porcentaje,
        'porcentaje_origen' => $origenPorcentaje,
        'maestro_vigente_desde' => $maestroUsado === null ? null : fecha($maestroUsado['vigente_desde'] ?? null),
        'comision_ars' => $comision,
        'combustible_ars' => round($importeCombustible, 2),
        'combustible_litros' => round($litros, 2),
        'combustible_imputado_ars' => round($combustibleImputado, 2),
        'combustible_sin_imputar_ars' => round($combustibleSinImputar, 2),
        'gastos_ars' => round($importeGastos, 2),
        'gastos_imputados_ars' => round($gastosImputados, 2),
        'gastos_sin_imputar_ars' => round($gastosSinImputar, 2),
        'gastos_pagados_de_caja_ars' => round($gastosDeCaja, 2),
        'otros_haberes_ars' => round($otrosHaberes, 2),
        'caja_ars' => round($cajaTotal, 2),
        'caja_por_tipo' => $cajaPorTipo,
        'costos_totales_ars' => $costosTotales,
        'margen_ars' => $margen,
        'margen_pct' => $margenPct,
        'consumo_l_100km' => $consumo,
        'lectura_regreso' => $tieneLecturaRegreso,
        'lectura_regreso_km' => $lecturaKm,
        'puede_cerrar' => $tramos !== [] && $tramosSinImporte === [] && $porcentaje !== null && $tieneLecturaRegreso,
        'cantidad_cargas_combustible' => count($combustible),
        'cantidad_gastos' => count($gastos),
    ];
}

// ============================================================================
// Agregados
// ============================================================================
$porEstado = [];
foreach ($detalle as $fila) {
    $estado = (string) $fila['estado'];
    $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;
}

$abiertosAntiguos = array_values(array_filter($detalle, static function (array $fila): bool {
    return $fila['estado'] === 'EN_CURSO' && ($fila['dias_abierto'] ?? 0) >= 3;
}));

// --- Combustible: agregado ---
$cargasTotales = 0;
$litrosTotales = 0.0;
$importeCombustibleTotal = 0.0;
$combustiblePorMoneda = [];
$combustiblePorEstado = [];
$cargasSinTramo = 0;
$cargasSinKilometraje = 0;
$cargasSinPrecio = 0;
$cargasSinLitros = 0;
$litrosSinImputar = 0.0;
$importeSinImputar = 0.0;
$filasCombustible = [];
$precios = [];

foreach ($detalle as $fila) {
    $id = (int) $fila['viaje_id'];
    foreach ($combustiblePorViaje[$id] ?? [] as $carga) {
        $cargasTotales++;
        $litros = num($carga['litros'] ?? null);
        $importe = num($carga['importe_ars'] ?? null);
        $litrosTotales += $litros;
        $importeCombustibleTotal += $importe;
        $moneda = (string) ($carga['moneda'] ?? 'ARS');
        if (! isset($combustiblePorMoneda[$moneda])) {
            $combustiblePorMoneda[$moneda] = ['cargas' => 0, 'importe' => 0.0];
        }
        $combustiblePorMoneda[$moneda]['cargas']++;
        $combustiblePorMoneda[$moneda]['importe'] += $importe;
        $estado = (string) $fila['estado'];
        if (! isset($combustiblePorEstado[$estado])) {
            $combustiblePorEstado[$estado] = ['cargas' => 0, 'litros' => 0.0, 'importe_ars' => 0.0];
        }
        $combustiblePorEstado[$estado]['cargas']++;
        $combustiblePorEstado[$estado]['litros'] += $litros;
        $combustiblePorEstado[$estado]['importe_ars'] += $importe;

        $tramoId = $carga['tramo_id'] ?? null;
        $sinImputar = $tramoId === null || ! isset($legIdsPorViaje[$id][(int) $tramoId]);
        if ($sinImputar) {
            $cargasSinTramo++;
            $litrosSinImputar += $litros;
            $importeSinImputar += $importe;
        }
        if (($carga['kilometraje'] ?? null) === null) {
            $cargasSinKilometraje++;
        }
        if (($carga['importe'] ?? null) === null || $importe <= 0) {
            $cargasSinPrecio++;
        }
        if ($litros <= 0) {
            $cargasSinLitros++;
        }
        if ($litros > 0 && $importe > 0) {
            $precios[] = $importe / $litros;
        }
        $filasCombustible[] = [
            'viaje_id' => $id,
            'viaje_numero' => $fila['numero'],
            'viaje_estado' => $fila['estado'],
            'fecha' => $carga['fecha'] ?? null,
            'litros' => round($litros, 2),
            'importe' => $carga['importe'] ?? null,
            'moneda' => $moneda,
            'cotizacion' => num($carga['cotizacion'] ?? null),
            'importe_ars' => round($importe, 2),
            'precio_ars_litro' => ($litros > 0 && $importe > 0) ? round($importe / $litros, 2) : null,
            'kilometraje' => $carga['kilometraje'] ?? null,
            'tramo_id' => $tramoId === null ? null : (int) $tramoId,
            'imputado_a_tramo' => ! $sinImputar,
            'estacion' => $carga['estacion'] ?? null,
            'comprobante' => $carga['comprobante'] ?? null,
            'observaciones' => $carga['observaciones'] ?? null,
        ];
    }
}

// --- Gastos: agregado ---
$gastosTotales = 0;
$gastosImporteTotal = 0.0;
$gastosDeCajaTotal = 0.0;
$gastosSinImputarTotal = 0.0;
$gastosPorCategoria = [];
$filasGastos = [];
foreach ($detalle as $fila) {
    $id = (int) $fila['viaje_id'];
    foreach ($gastosPorViaje[$id] ?? [] as $gasto) {
        $gastosTotales++;
        $importe = num($gasto['importe_ars'] ?? null);
        $gastosImporteTotal += $importe;
        if ((int) ($gasto['pagado_de_caja'] ?? 0) === 1) {
            $gastosDeCajaTotal += $importe;
        }
        $categoriaId = $gasto['categoria_id'] ?? null;
        $categoria = $categoriaId === null ? 'SIN_CATEGORIA' : ($categoriasPorId[(int) $categoriaId] ?? ('categoria#' . $categoriaId));
        if (! isset($gastosPorCategoria[$categoria])) {
            $gastosPorCategoria[$categoria] = ['gastos' => 0, 'importe_ars' => 0.0];
        }
        $gastosPorCategoria[$categoria]['gastos']++;
        $gastosPorCategoria[$categoria]['importe_ars'] += $importe;
        $tramoId = $gasto['tramo_id'] ?? null;
        $sinImputar = $tramoId === null || ! isset($legIdsPorViaje[$id][(int) $tramoId]);
        if ($sinImputar) {
            $gastosSinImputarTotal += $importe;
        }
        $filasGastos[] = [
            'viaje_id' => $id,
            'viaje_numero' => $fila['numero'],
            'viaje_estado' => $fila['estado'],
            'fecha' => $gasto['fecha'] ?? null,
            'categoria' => $categoria,
            'importe' => $gasto['importe'] ?? null,
            'moneda' => $gasto['moneda'] ?? null,
            'importe_ars' => round($importe, 2),
            'pagado_de_caja' => (int) ($gasto['pagado_de_caja'] ?? 0) === 1,
            'tramo_id' => $tramoId === null ? null : (int) $tramoId,
            'imputado_a_tramo' => ! $sinImputar,
            'comprobante' => $gasto['comprobante'] ?? null,
            'observaciones' => $gasto['observaciones'] ?? null,
        ];
    }
}

// --- Caja y haberes: agregado ---
$cajaPorTipo = [];
$cajaTotal = 0.0;
$cajaMovimientos = 0;
foreach ($cajaPorViaje as $filas) {
    foreach ($filas as $fila) {
        $cajaMovimientos++;
        $tipo = (string) ($fila['tipo'] ?? 'SIN_TIPO');
        $importe = num($fila['importe_ars'] ?? null);
        $cajaPorTipo[$tipo] = ($cajaPorTipo[$tipo] ?? 0.0) + $importe;
        $cajaTotal += $importe;
    }
}

$haberesPorConcepto = [];
$haberesTotal = 0.0;
$haberesMovimientos = 0;
foreach ($haberesPorViaje as $filas) {
    foreach ($filas as $fila) {
        $haberesMovimientos++;
        $concepto = (string) ($fila['concepto'] ?? 'SIN_CONCEPTO');
        $importe = num($fila['importe_ars'] ?? null);
        $haberesPorConcepto[$concepto] = ($haberesPorConcepto[$concepto] ?? 0.0) + $importe;
        $haberesTotal += $importe;
    }
}

// --- Origen de los porcentajes ---
$porcentajesPorOrigen = [];
$viajesSinPorcentaje = [];
foreach ($detalle as $fila) {
    $origen = (string) ($fila['porcentaje_origen'] ?? 'SIN_CHOFER');
    $porcentajesPorOrigen[$origen] = ($porcentajesPorOrigen[$origen] ?? 0) + 1;
    if ($fila['porcentaje_liquidacion'] === null) {
        $viajesSinPorcentaje[] = $fila['numero'];
    }
}

// --- Viajes que no pueden cerrarse ---
$noCierran = [];
foreach ($detalle as $fila) {
    $motivos = [];
    if ($fila['tramos'] === 0) {
        $motivos[] = 'sin tramos';
    }
    if ($fila['tramos_sin_importe'] !== []) {
        $motivos[] = 'tramos sin fletes (orden ' . implode(', ', $fila['tramos_sin_importe']) . ')';
    }
    if ($fila['porcentaje_liquidacion'] === null) {
        $motivos[] = 'sin porcentaje de liquidacion';
    }
    if ($fila['estado'] === 'FINALIZADO' && ! $fila['lectura_regreso']) {
        $motivos[] = 'sin lectura de regreso';
    }
    if ($motivos !== []) {
        $noCierran[] = [
            'numero' => $fila['numero'],
            'estado' => $fila['estado'],
            'motivos' => $motivos,
        ];
    }
}

// --- Lecturas faltantes en finalizados ---
$finalizadosSinLectura = [];
foreach ($detalle as $fila) {
    if ($fila['estado'] === 'FINALIZADO' && ! $fila['lectura_regreso']) {
        $finalizadosSinLectura[] = [
            'numero' => $fila['numero'],
            'km_salida' => $fila['km_salida'],
            'km_regreso' => $fila['km_regreso'],
        ];
    }
}

// --- Totales economicos ---
$totales = [
    'facturado_ars' => 0.0,
    'comision_ars' => 0.0,
    'combustible_ars' => 0.0,
    'gastos_ars' => 0.0,
    'otros_haberes_ars' => 0.0,
    'margen_ars' => 0.0,
    'costos_sin_imputar_ars' => 0.0,
];
foreach ($detalle as $fila) {
    $totales['facturado_ars'] += num($fila['facturacion_ars']);
    $totales['comision_ars'] += num($fila['comision_ars']);
    $totales['combustible_ars'] += num($fila['combustible_ars']);
    $totales['gastos_ars'] += num($fila['gastos_ars']);
    $totales['otros_haberes_ars'] += num($fila['otros_haberes_ars']);
    $totales['margen_ars'] += num($fila['margen_ars']);
    $totales['costos_sin_imputar_ars'] += num($fila['combustible_sin_imputar_ars']) + num($fila['gastos_sin_imputar_ars']);
}
foreach ($totales as $clave => $valor) {
    $totales[$clave] = round($valor, 2);
}

// --- Rankings ---
$conMargen = array_values(array_filter($detalle, static fn (array $f): bool => $f['margen_ars'] !== null));
usort($conMargen, static fn (array $a, array $b): int => $b['margen_ars'] <=> $a['margen_ars']);
$topMargen = array_slice($conMargen, 0, 10);
$peoresMargen = array_slice(array_reverse($conMargen), 0, 10);

$conCombustible = array_values(array_filter($detalle, static fn (array $f): bool => $f['cantidad_cargas_combustible'] > 0));
usort($conCombustible, static fn (array $a, array $b): int => $b['combustible_ars'] <=> $a['combustible_ars']);

// --- Alertas ---
if ($cargasTotales === 0) {
    $alertas[] = 'No hay ninguna carga de combustible cargada en los viajes operativos.';
}
if ($cargasSinTramo > 0) {
    $alertas[] = $cargasSinTramo . ' cargas de combustible sin imputar a un tramo (' . round($importeSinImputar, 2) . ' ARS).';
}
if ($cargasSinKilometraje > 0) {
    $alertas[] = $cargasSinKilometraje . ' cargas de combustible sin kilometraje cargado.';
}
if ($cargasSinPrecio > 0) {
    $alertas[] = $cargasSinPrecio . ' cargas de combustible sin precio/importe.';
}
if ($cargasSinLitros > 0) {
    $alertas[] = $cargasSinLitros . ' cargas de combustible con litros en cero o negativos.';
}
if ($gastosSinImputarTotal > 0) {
    $alertas[] = 'Gastos sin imputar a tramo: ' . round($gastosSinImputarTotal, 2) . ' ARS.';
}
if ($viajesSinPorcentaje !== []) {
    $alertas[] = 'Viajes sin porcentaje de liquidacion resuelto: ' . implode(', ', $viajesSinPorcentaje) . '.';
}
if ($abiertosAntiguos !== []) {
    $alertas[] = count($abiertosAntiguos) . ' viajes EN_CURSO con 3 dias o mas de antiguedad.';
}
if ($finalizadosSinLectura !== []) {
    $alertas[] = count($finalizadosSinLectura) . ' viajes FINALIZADOS sin lectura de regreso del equipo.';
}
if ($noCierran !== []) {
    $alertas[] = count($noCierran) . ' viajes con pendientes que impiden el cierre.';
}

$preciosOrdenados = $precios;
sort($preciosOrdenados);
$precioMedio = $preciosOrdenados === [] ? null : round(array_sum($preciosOrdenados) / count($preciosOrdenados), 2);
$precioMin = $preciosOrdenados === [] ? null : round($preciosOrdenados[0], 2);
$precioMax = $preciosOrdenados === [] ? null : round($preciosOrdenados[count($preciosOrdenados) - 1], 2);

$consumos = array_values(array_filter(array_map(
    static fn (array $f): ?float => $f['consumo_l_100km'],
    $detalle,
), static fn (?float $v): bool => $v !== null));
$consumoMedio = $consumos === [] ? null : round(array_sum($consumos) / count($consumos), 2);

$salidas = array_values(array_filter(array_map(
    static fn (array $f): ?string => $f['fecha_salida'],
    $detalle,
), static fn (?string $v): bool => $v !== null));
sort($salidas);

$informe = [
    'ok' => true,
    'generado_en' => date('Y-m-d H:i:s'),
    'empresa' => ['id' => $empresaId, 'nombre' => $empresaNombre],
    'alcance' => [
        'viajes_analizados' => count($detalle),
        'filtro_viaje' => $viajeId,
        'tramos_anulados_excluidos' => $tramosAnulados,
    ],
    'porcentaje_defecto_empresa' => $defaultEmpresa,
    'porcentaje_defecto_empresa_id' => $defaultEmpresaId,
    'panorama' => [
        'viajes_por_estado' => $porEstado,
        'salida_mas_antigua' => $salidas === [] ? null : $salidas[0],
        'salida_mas_reciente' => $salidas === [] ? null : $salidas[count($salidas) - 1],
        'viajes_en_curso_antiguos' => count($abiertosAntiguos),
        'viajes_finalizados_sin_lectura' => count($finalizadosSinLectura),
        'viajes_que_no_pueden_cerrar' => count($noCierran),
        'porcentajes_por_origen' => $porcentajesPorOrigen,
    ],
    'combustible' => [
        'cargas' => $cargasTotales,
        'litros' => round($litrosTotales, 2),
        'importe_ars' => round($importeCombustibleTotal, 2),
        'precio_medio_ars_litro' => $precioMedio,
        'precio_min_ars_litro' => $precioMin,
        'precio_max_ars_litro' => $precioMax,
        'por_moneda' => $combustiblePorMoneda,
        'por_estado_viaje' => $combustiblePorEstado,
        'cargas_sin_tramo' => $cargasSinTramo,
        'cargas_sin_tramo_importe_ars' => round($importeSinImputar, 2),
        'cargas_sin_tramo_litros' => round($litrosSinImputar, 2),
        'cargas_sin_kilometraje' => $cargasSinKilometraje,
        'cargas_sin_precio' => $cargasSinPrecio,
        'cargas_sin_litros' => $cargasSinLitros,
        'consumo_medio_l_100km' => $consumoMedio,
        'viajes_con_combustible' => count($conCombustible),
    ],
    'gastos' => [
        'gastos' => $gastosTotales,
        'importe_ars' => round($gastosImporteTotal, 2),
        'pagados_de_caja_ars' => round($gastosDeCajaTotal, 2),
        'sin_imputar_ars' => round($gastosSinImputarTotal, 2),
        'por_categoria' => $gastosPorCategoria,
    ],
    'caja' => [
        'movimientos' => $cajaMovimientos,
        'importe_ars' => round($cajaTotal, 2),
        'por_tipo' => $cajaPorTipo,
    ],
    'otros_haberes' => [
        'movimientos' => $haberesMovimientos,
        'importe_ars' => round($haberesTotal, 2),
        'por_concepto' => $haberesPorConcepto,
    ],
    'economia' => [
        'totales' => $totales,
        'top_margen' => $topMargen,
        'peores_margen' => $peoresMargen,
    ],
    'tramos_incompletos' => $noCierran,
    'finalizados_sin_lectura' => $finalizadosSinLectura,
    'alertas' => $alertas,
    'avisos_php' => $avisosPhp,
    'viajes' => $detalle,
    'filas_combustible' => $filasCombustible,
    'filas_gastos' => $filasGastos,
];

echo json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
