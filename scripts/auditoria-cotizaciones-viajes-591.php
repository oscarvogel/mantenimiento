<?php

declare(strict_types=1);

/**
 * Auditoría histórica masiva SOLO LECTURA - Issue #591.
 *
 * No modifica registros. No recalcula históricos con cotización actual.
 * Extrae movimientos monetarios de viajes operativos (abiertos/cerrados/finalizados;
 * activos y anulados separados) y los clasifica para revisión administrativa.
 *
 * Seguridad:
 * - Token efímero en cabecera X-Audit-Token.
 * - START TRANSACTION READ ONLY cuando el motor lo soporta.
 * - Solo SELECT/INFORMATION_SCHEMA.
 * - El runner borra este archivo remoto en finally.
 */

$tokenEsperado = '__AUDIT_TOKEN__';
$isCli = PHP_SAPI === 'cli';

if (! $isCli) {
    $tokenRecibido = $_SERVER['HTTP_X_AUDIT_TOKEN'] ?? '';
    if (! is_string($tokenRecibido) || strlen($tokenEsperado) < 32 || ! hash_equals($tokenEsperado, $tokenRecibido)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Token invalido o ausente.'], JSON_UNESCAPED_UNICODE);
        exit(1);
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
    echo json_encode(['error' => 'No se encontro .env legible.'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
    $linea = trim($linea);
    if ($linea === '' || str_starts_with($linea, '#')) {
        continue;
    }
    if (! preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s*(.*)$/', $linea, $m)) {
        continue;
    }
    $valor = trim($m[2]);
    if (
        (str_starts_with($valor, "'") && str_ends_with($valor, "'"))
        || (str_starts_with($valor, '"') && str_ends_with($valor, '"'))
    ) {
        $valor = substr($valor, 1, -1);
    }
    $env[$m[1]] = $valor;
}

defined('ENVIRONMENT') || define('ENVIRONMENT', $env['CI_ENVIRONMENT'] ?? 'production');

set_error_handler(static function (int $severidad, string $mensaje, string $archivo = '', int $linea = 0): bool {
    global $avisosPhp;
    $avisosPhp[] = $mensaje . ' (' . basename($archivo) . ':' . $linea . ')';
    return true;
});

$avisosPhp = [];
register_shutdown_function(static function () use (&$avisosPhp): void {
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
            'avisos_php' => $avisosPhp,
        ], JSON_UNESCAPED_UNICODE);
    }
});

require $paths->systemDirectory . '/Boot.php';
(new class extends \CodeIgniter\Boot {
    public static function bootForAudit(\Config\Paths $paths): void
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
})::bootForAudit($paths);

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

$soloLectura = false;
$notaTransaccion = null;
try {
    $db->query('START TRANSACTION READ ONLY');
    $soloLectura = true;
} catch (Throwable $error) {
    $notaTransaccion = 'START TRANSACTION READ ONLY no soportado: ' . $error->getMessage();
}

/** @return list<array<string,mixed>> */
function filas($db, string $sql, array $params = []): array
{
    try {
        $resultado = $params === [] ? $db->query($sql) : $db->query($sql, $params);
        return $resultado->getResultArray();
    } catch (Throwable $error) {
        return [['__error' => $error->getMessage(), '__sql' => $sql]];
    }
}

/** @return array<string,array<string,array<string,mixed>>> */
function cargarEsquema($db): array
{
    $esquema = [];
    $rows = filas($db, 'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT '
        . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
    foreach ($rows as $columna) {
        $esquema[(string) $columna['TABLE_NAME']][(string) $columna['COLUMN_NAME']] = [
            'tipo' => (string) $columna['DATA_TYPE'],
            'definicion' => (string) $columna['COLUMN_TYPE'],
            'nullable' => (string) $columna['IS_NULLABLE'] === 'YES',
            'default' => $columna['COLUMN_DEFAULT'],
        ];
    }
    return $esquema;
}

/** @param array<string,array<string,mixed>> $columnas @param list<string> $deseadas @return list<string> */
function columnasDisponibles(array $columnas, array $deseadas): array
{
    return array_values(array_filter($deseadas, static fn (string $c): bool => array_key_exists($c, $columnas)));
}

/** @return list<array<string,mixed>> */
function tablaSimple($db, array $esquema, string $tabla, array $deseadas, string $donde = '', int $limite = 50000): array
{
    if (! isset($esquema[$tabla])) {
        return [];
    }
    $seleccion = columnasDisponibles($esquema[$tabla], $deseadas);
    if ($seleccion === []) {
        return [];
    }
    $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $seleccion))
        . ' FROM `' . $tabla . '`' . ($donde === '' ? '' : ' WHERE ' . $donde) . ' LIMIT ' . $limite;
    return filas($db, $sql);
}

function dec($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return number_format((float) $value, 8, '.', '');
    }
    return null;
}

function decFloat($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float) $value : null;
}

function moneyEqual(?float $a, ?float $b, float $tol = 0.00005): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) <= $tol;
}

$esquema = cargarEsquema($db);

// Contexto
$empresas = tablaSimple($db, $esquema, 'empresas', ['id', 'razon_social', 'nombre_fantasia', 'activo', 'estado', 'deleted_at']);
$viajes = tablaSimple($db, $esquema, 'viajes_operativos', [
    'id', 'empresa_id', 'sucursal_id', 'numero', 'estado',
    'fecha_salida_planificada', 'fecha_salida_real', 'fecha_regreso_real', 'fecha_regreso_planificada',
    'facturado_ars', 'combustible_ars', 'gastos_ars', 'comision_ars', 'otros_haberes_ars',
    'liquidacion_ars', 'margen_ars', 'cerrado_at', 'anulado_at', 'deleted_at', 'created_at', 'updated_at', 'observaciones',
]);
$asignaciones = tablaSimple($db, $esquema, 'viaje_operativo_asignaciones', [
    'id', 'viaje_id', 'empresa_id', 'rol', 'equipo_id', 'empleado_id', 'porcentaje_liquidacion', 'desde', 'hasta', 'deleted_at',
]);
$equipos = tablaSimple($db, $esquema, 'equipos', ['id', 'empresa_id', 'codigo', 'patente', 'interno', 'deleted_at']);
$empleados = tablaSimple($db, $esquema, 'empleados', ['id', 'empresa_id', 'nombre', 'apellido', 'activo', 'deleted_at']);
$monedasCatalogo = tablaSimple($db, $esquema, 'viajes_monedas', ['id', 'codigo', 'nombre', 'activo']);
$cotizacionesEmpresa = tablaSimple($db, $esquema, 'viajes_cotizaciones', ['id', 'empresa_id', 'moneda', 'cotizacion', 'created_at', 'updated_at']);

$empresasById = [];
foreach ($empresas as $e) {
    $empresasById[(string) $e['id']] = $e;
}
$viajesById = [];
foreach ($viajes as $v) {
    $viajesById[(string) $v['id']] = $v;
}
$equiposById = [];
foreach ($equipos as $eq) {
    $equiposById[(string) $eq['id']] = $eq;
}
$empleadosById = [];
foreach ($empleados as $em) {
    $empleadosById[(string) $em['id']] = $em;
}
$monedasActivas = [];
foreach ($monedasCatalogo as $m) {
    $code = strtoupper(trim((string) ($m['codigo'] ?? '')));
    if ($code !== '') {
        $monedasActivas[$code] = $m;
    }
}
$catalogRateByCompanyCurrency = [];
foreach ($cotizacionesEmpresa as $r) {
    $key = (string) ($r['empresa_id'] ?? '') . '|' . strtoupper(trim((string) ($r['moneda'] ?? '')));
    $catalogRateByCompanyCurrency[$key] = $r;
}

// Mapa viaje -> camión/chofer
$tripTruck = [];
$tripDriver = [];
foreach ($asignaciones as $a) {
    $vid = (string) ($a['viaje_id'] ?? '');
    $role = strtoupper((string) ($a['rol'] ?? ''));
    if ($role === 'TRACTOR') {
        $eq = $equiposById[(string) ($a['equipo_id'] ?? '')] ?? null;
        if ($eq) {
            $label = trim((string) ($eq['patente'] ?? ''));
            $code = trim((string) ($eq['codigo'] ?? ''));
            $tripTruck[$vid] = $label !== '' ? ($label . ($code !== '' ? ' · ' . $code : '')) : $code;
        }
    }
    if ($role === 'CHOFER') {
        $em = $empleadosById[(string) ($a['empleado_id'] ?? '')] ?? null;
        if ($em) {
            $tripDriver[$vid] = trim((string) ($em['nombre'] ?? '') . ' ' . (string) ($em['apellido'] ?? ''));
        }
    }
}

// Descubrir evidencia de confirmación explícita de cotización==1 en auditoría JSON
$confirmEvidence = []; // key: tipo|movimiento_id => ['confirmed'=>bool,'source'=>'...']
if (isset($esquema['viaje_operativo_movimientos_auditoria'])) {
    $audRows = tablaSimple($db, $esquema, 'viaje_operativo_movimientos_auditoria', [
        'id', 'empresa_id', 'viaje_id', 'movimiento_tipo', 'movimiento_id', 'accion', 'datos_anteriores', 'datos_nuevos', 'usuario_id', 'created_at',
    ], '', 100000);
    foreach ($audRows as $ar) {
        $tipo = strtoupper((string) ($ar['movimiento_tipo'] ?? ''));
        $mid = (string) ($ar['movimiento_id'] ?? '');
        $key = $tipo . '|' . $mid;
        $payloads = [];
        foreach (['datos_nuevos', 'datos_anteriores'] as $f) {
            $raw = $ar[$f] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payloads[] = $decoded;
                }
            }
        }
        foreach ($payloads as $p) {
            // Evidencia explícita si el payload trae confirmación o motivo que indique confirmación.
            foreach (['confirma_cotizacion_uno', 'confirmForeignRateOne', 'cotizacion_uno_confirmada'] as $flag) {
                if (array_key_exists($flag, $p)) {
                    $v = $p[$flag];
                    $confirmed = $v === true || $v === 1 || $v === '1' || $v === 'true';
                    if ($confirmed) {
                        $confirmEvidence[$key] = ['confirmed' => true, 'source' => 'auditoria_json:' . $flag, 'audit_id' => $ar['id'] ?? null];
                    }
                }
            }
        }
    }
}

$movementDefs = [
    [
        'table' => 'viaje_operativo_caja_movimientos',
        'tipo_movimiento' => 'CAJA',
        'circuito' => 'Caja del viaje',
        'amount' => 'importe', 'currency' => 'moneda', 'rate' => 'cotizacion', 'ars' => 'importe_ars',
        'date' => 'fecha',
    ],
    [
        'table' => 'viaje_operativo_combustible',
        'tipo_movimiento' => 'COMBUSTIBLE',
        'circuito' => 'Combustible',
        'amount' => 'importe', 'currency' => 'moneda', 'rate' => 'cotizacion', 'ars' => 'importe_ars',
        'date' => 'fecha',
    ],
    [
        'table' => 'viaje_operativo_gastos',
        'tipo_movimiento' => 'GASTO',
        'circuito' => 'Gastos del viaje',
        'amount' => 'importe', 'currency' => 'moneda', 'rate' => 'cotizacion', 'ars' => 'importe_ars',
        'date' => 'fecha',
    ],
    [
        'table' => 'viaje_operativo_otros_haberes',
        'tipo_movimiento' => 'HABER',
        'circuito' => 'Otros haberes',
        'amount' => 'importe', 'currency' => 'moneda', 'rate' => 'cotizacion', 'ars' => 'importe_ars',
        'date' => 'fecha',
    ],
];

$observaciones = [];
$todosMovimientos = [];
$resumen = [
    'total_movimientos_auditados' => 0,
    'total_viajes_auditados' => 0,
    'total_sospechosos' => 0,
    'total_error_confirmado' => 0,
    'total_revision_administrativa' => 0,
    'total_ok' => 0,
    'distribucion_clasificacion' => [],
    'distribucion_estado_viaje' => [],
    'distribucion_circuito' => [],
    'viajes_afectados' => [],
    'impacto_estimado_sospechoso_ars' => 0.0,
];

$tripIdsSeen = [];

foreach ($movementDefs as $def) {
    $table = $def['table'];
    if (! isset($esquema[$table])) {
        continue;
    }
    $cols = $esquema[$table];
    $select = columnasDisponibles($cols, [
        'id', 'empresa_id', 'sucursal_id', 'viaje_id', 'fecha', 'tipo', 'concepto', 'categoria_id', 'concepto_id',
        'empleado_id', 'tramo_id', 'importe', 'moneda', 'cotizacion', 'importe_ars', 'litros',
        'observaciones', 'comprobante', 'pagado_por', 'pagado_de_caja', 'anulado_at', 'deleted_at',
        'created_at', 'created_by', 'updated_at', 'updated_by',
    ]);
    if ($select === []) {
        continue;
    }
    $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $select))
        . ' FROM `' . $table . '` ORDER BY id ASC LIMIT 100000';
    $rows = filas($db, $sql);

    foreach ($rows as $row) {
        if (isset($row['__error'])) {
            $observaciones[] = ['tabla' => $table, 'error' => $row['__error']];
            continue;
        }
        $viajeId = (string) ($row['viaje_id'] ?? '');
        $tripIdsSeen[$viajeId] = true;
        $viaje = $viajesById[$viajeId] ?? [];
        $empresaId = (string) ($row['empresa_id'] ?? ($viaje['empresa_id'] ?? ''));
        $empresa = $empresasById[$empresaId] ?? [];
        $sucursalId = (string) ($row['sucursal_id'] ?? ($viaje['sucursal_id'] ?? ''));

        $currencyRaw = $row[$def['currency']] ?? null;
        $currency = strtoupper(trim((string) ($currencyRaw ?? '')));
        $amount = decFloat($row[$def['amount']] ?? null);
        $rate = decFloat($row[$def['rate']] ?? null);
        $arsStored = decFloat($row[$def['ars']] ?? null);
        $expectedArs = ($amount !== null && $rate !== null) ? round($amount * $rate, 4) : null;
        $diffArs = ($expectedArs !== null && $arsStored !== null) ? round($expectedArs - $arsStored, 4) : null;
        $diffAbs = $diffArs === null ? null : abs($diffArs);

        $anulado = ! empty($row['anulado_at']);
        $eliminado = ! empty($row['deleted_at']);
        $estadoRegistro = $eliminado ? 'ELIMINADO_LOGICO' : ($anulado ? 'ANULADO' : 'ACTIVO');
        $estadoViaje = strtoupper((string) ($viaje['estado'] ?? ''));

        $key = $def['tipo_movimiento'] . '|' . (string) ($row['id'] ?? '');
        $evidence = $confirmEvidence[$key] ?? null;
        $catalogKey = $empresaId . '|' . $currency;
        $catalog = $catalogRateByCompanyCurrency[$catalogKey] ?? null;
        $catalogRate = $catalog ? decFloat($catalog['cotizacion'] ?? null) : null;

        $clasificaciones = [];
        $motivos = [];
        $severidad = 'OK';

        $push = function (string $clasificacion, string $sev, string $motivo) use (&$clasificaciones, &$motivos, &$severidad): void {
            $clasificaciones[] = $clasificacion;
            $motivos[] = $motivo;
            $rank = ['OK' => 0, 'SOSPECHOSO' => 1, 'REVISION_ADMINISTRATIVA' => 2, 'ERROR_CONFIRMADO' => 3];
            if (($rank[$sev] ?? 0) > ($rank[$severidad] ?? 0)) {
                $severidad = $sev;
            }
        };

        if ($currency === '') {
            $push('MONEDA_NO_IDENTIFICABLE', 'ERROR_CONFIRMADO', 'El movimiento no tiene moneda identificable.');
        } elseif ($currency === 'ARS') {
            if ($rate === null || ! moneyEqual($rate, 1.0, 0.0000005)) {
                $push('ARS_COTIZACION_DISTINTA_DE_1', 'ERROR_CONFIRMADO', 'Moneda ARS con cotización distinta de 1 o nula.');
            }
        } else {
            if ($rate === null) {
                $push('MONEDA_EXTRANJERA_SIN_COTIZACION', 'ERROR_CONFIRMADO', 'Moneda extranjera sin cotización.');
            } elseif ($rate <= 0) {
                $push('COTIZACION_CERO_NEGATIVA_INVALIDA', 'ERROR_CONFIRMADO', 'Cotización cero, negativa o inválida.');
            } elseif (moneyEqual($rate, 1.0, 0.0000005)) {
                if ($evidence && ! empty($evidence['confirmed'])) {
                    $push('MONEDA_EXTRANJERA_COTIZACION_1_CONFIRMADA', 'REVISION_ADMINISTRATIVA', 'Cotización 1 confirmada explícitamente según evidencia en auditoría.');
                } else {
                    $push('MONEDA_EXTRANJERA_COTIZACION_1', 'SOSPECHOSO', 'Moneda extranjera con cotización 1; sin evidencia de confirmación explícita en base.');
                }
            } elseif ($catalogRate !== null && $catalogRate > 0) {
                $delta = abs($rate - $catalogRate) / $catalogRate;
                if ($delta > 0.10) {
                    $push('COTIZACION_DIFIERE_CATALOGO_MAS_10', 'REVISION_ADMINISTRATIVA', 'La cotización difiere más del 10% respecto de la cotización actual configurada; no es error automático sin evidencia histórica.');
                }
            }
        }

        if ($diffAbs !== null && $diffAbs > 0.02) {
            $push('DIFERENCIA_IMPORTE_ARS', 'ERROR_CONFIRMADO', 'Diferencia entre importe original × cotización y ARS almacenado supera tolerancia de redondeo.');
        }

        if ($clasificaciones === []) {
            $push('OK', 'OK', 'Sin observaciones por reglas de cotización.');
        }

        $registro = [
            'viaje' => $viaje['numero'] ?? $viajeId,
            'viaje_id' => $viajeId,
            'estado_viaje' => $estadoViaje,
            'estado_registro' => $estadoRegistro,
            'empresa' => $empresa['razon_social'] ?? ($empresa['nombre_fantasia'] ?? $empresaId),
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'tipo_movimiento' => $def['tipo_movimiento'],
            'tipo_registro' => $row['tipo'] ?? null,
            'circuito' => $def['circuito'],
            'movimiento_id' => $row['id'] ?? null,
            'tabla' => $table,
            'fecha' => $row[$def['date']] ?? $row['created_at'] ?? null,
            'camion' => $tripTruck[$viajeId] ?? null,
            'chofer' => $tripDriver[$viajeId] ?? null,
            'importe_original' => $amount,
            'moneda' => $currency !== '' ? $currency : null,
            'cotizacion_registrada' => $rate,
            'cotizacion_vigente_catalogo' => $catalogRate,
            'importe_ars_registrado' => $arsStored,
            'importe_ars_esperado' => $expectedArs,
            'diferencia_ars' => $diffArs,
            'clasificacion' => implode('|', array_unique($clasificaciones)),
            'severidad' => $severidad,
            'motivos' => $motivos,
            'confirmacion_explicita_evidencia' => $evidence,
            'anulado_at' => $row['anulado_at'] ?? null,
            'deleted_at' => $row['deleted_at'] ?? null,
            'observaciones' => $row['observaciones'] ?? null,
            'comprobante' => $row['comprobante'] ?? null,
            'pagado_por' => $row['pagado_por'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];

        $todosMovimientos[] = $registro;
        $resumen['total_movimientos_auditados']++;
        $resumen['distribucion_circuito'][$def['circuito']] = ($resumen['distribucion_circuito'][$def['circuito']] ?? 0) + 1;
        $resumen['distribucion_estado_viaje'][$estadoViaje !== '' ? $estadoViaje : 'SIN_ESTADO'] = ($resumen['distribucion_estado_viaje'][$estadoViaje !== '' ? $estadoViaje : 'SIN_ESTADO'] ?? 0) + 1;

        foreach (array_unique($clasificaciones) as $c) {
            $resumen['distribucion_clasificacion'][$c] = ($resumen['distribucion_clasificacion'][$c] ?? 0) + 1;
        }

        if ($severidad === 'OK') {
            $resumen['total_ok']++;
        } else {
            $resumen['viajes_afectados'][$viajeId] = $viaje['numero'] ?? $viajeId;
            if ($severidad === 'SOSPECHOSO') {
                $resumen['total_sospechosos']++;
                if ($currency !== 'ARS' && $currency !== '' && moneyEqual($rate, 1.0, 0.0000005) && $catalogRate !== null && $amount !== null) {
                    $resumen['impacto_estimado_sospechoso_ars'] += abs(($amount * $catalogRate) - ($arsStored ?? 0.0));
                } elseif ($diffAbs !== null) {
                    $resumen['impacto_estimado_sospechoso_ars'] += $diffAbs;
                }
            } elseif ($severidad === 'ERROR_CONFIRMADO') {
                $resumen['total_error_confirmado']++;
            } elseif ($severidad === 'REVISION_ADMINISTRATIVA') {
                $resumen['total_revision_administrativa']++;
            }
            $observaciones[] = $registro;
        }
    }
}

// Tramos: flete facturacion/liquidacion (monetarios). Se reportan como circuitos separados.
if (isset($esquema['viaje_operativo_tramos'])) {
    $cols = $esquema['viaje_operativo_tramos'];
    $select = columnasDisponibles($cols, [
        'id', 'empresa_id', 'sucursal_id', 'viaje_id', 'orden', 'origen', 'destino', 'fecha_carga', 'fecha_descarga',
        'moneda', 'cotizacion', 'flete_facturacion', 'flete_facturacion_ars',
        'moneda_liquidacion', 'cotizacion_liquidacion', 'flete_liquidacion', 'flete_liquidacion_ars',
        'anulado_at', 'deleted_at', 'created_at', 'updated_at', 'observaciones', 'motivo_anulacion',
    ]);
    if ($select !== []) {
        $sql = 'SELECT ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $select))
            . ' FROM `viaje_operativo_tramos` ORDER BY id ASC LIMIT 100000';
        $rows = filas($db, $sql);
        foreach ($rows as $row) {
            foreach ([
                ['label' => 'Tramo facturacion', 'tipo' => 'TRAMO_FACTURACION', 'currency' => 'moneda', 'rate' => 'cotizacion', 'amount' => 'flete_facturacion', 'ars' => 'flete_facturacion_ars'],
                ['label' => 'Tramo liquidacion', 'tipo' => 'TRAMO_LIQUIDACION', 'currency' => 'moneda_liquidacion', 'rate' => 'cotizacion_liquidacion', 'amount' => 'flete_liquidacion', 'ars' => 'flete_liquidacion_ars'],
            ] as $part) {
                $amount = decFloat($row[$part['amount']] ?? null);
                $currency = strtoupper(trim((string) ($row[$part['currency']] ?? '')));
                $rate = decFloat($row[$part['rate']] ?? null);
                $arsStored = decFloat($row[$part['ars']] ?? null);
                // Omitir tramos sin importe cargado
                if ($amount === null && $arsStored === null) {
                    continue;
                }
                $viajeId = (string) ($row['viaje_id'] ?? '');
                $tripIdsSeen[$viajeId] = true;
                $viaje = $viajesById[$viajeId] ?? [];
                $empresaId = (string) ($row['empresa_id'] ?? ($viaje['empresa_id'] ?? ''));
                $empresa = $empresasById[$empresaId] ?? [];
                $expectedArs = ($amount !== null && $rate !== null) ? round($amount * $rate, 4) : null;
                $diffArs = ($expectedArs !== null && $arsStored !== null) ? round($expectedArs - $arsStored, 4) : null;
                $diffAbs = $diffArs === null ? null : abs($diffArs);
                $anulado = ! empty($row['anulado_at']);
                $eliminado = ! empty($row['deleted_at']);
                $estadoRegistro = $eliminado ? 'ELIMINADO_LOGICO' : ($anulado ? 'ANULADO' : 'ACTIVO');
                $estadoViaje = strtoupper((string) ($viaje['estado'] ?? ''));
                $catalogKey = $empresaId . '|' . $currency;
                $catalog = $catalogRateByCompanyCurrency[$catalogKey] ?? null;
                $catalogRate = $catalog ? decFloat($catalog['cotizacion'] ?? null) : null;
                $clasificaciones = [];
                $motivos = [];
                $severidad = 'OK';
                $push = function (string $clasificacion, string $sev, string $motivo) use (&$clasificaciones, &$motivos, &$severidad): void {
                    $clasificaciones[] = $clasificacion;
                    $motivos[] = $motivo;
                    $rank = ['OK' => 0, 'SOSPECHOSO' => 1, 'REVISION_ADMINISTRATIVA' => 2, 'ERROR_CONFIRMADO' => 3];
                    if (($rank[$sev] ?? 0) > ($rank[$severidad] ?? 0)) {
                        $severidad = $sev;
                    }
                };
                if ($currency === '') {
                    $push('MONEDA_NO_IDENTIFICABLE', 'ERROR_CONFIRMADO', 'Tramo sin moneda identificable.');
                } elseif ($currency === 'ARS') {
                    if ($rate === null || ! moneyEqual($rate, 1.0, 0.0000005)) {
                        $push('ARS_COTIZACION_DISTINTA_DE_1', 'ERROR_CONFIRMADO', 'Moneda ARS con cotización distinta de 1 o nula.');
                    }
                } else {
                    if ($rate === null) {
                        $push('MONEDA_EXTRANJERA_SIN_COTIZACION', 'ERROR_CONFIRMADO', 'Moneda extranjera sin cotización.');
                    } elseif ($rate <= 0) {
                        $push('COTIZACION_CERO_NEGATIVA_INVALIDA', 'ERROR_CONFIRMADO', 'Cotización cero, negativa o inválida.');
                    } elseif (moneyEqual($rate, 1.0, 0.0000005)) {
                        $push('MONEDA_EXTRANJERA_COTIZACION_1', 'SOSPECHOSO', 'Moneda extranjera con cotización 1; sin evidencia de confirmación explícita en base.');
                    } elseif ($catalogRate !== null && $catalogRate > 0 && abs($rate - $catalogRate) / $catalogRate > 0.10) {
                        $push('COTIZACION_DIFIERE_CATALOGO_MAS_10', 'REVISION_ADMINISTRATIVA', 'Difiere >10% del catálogo actual; revisión administrativa, no error automático.');
                    }
                }
                if ($diffAbs !== null && $diffAbs > 0.02) {
                    $push('DIFERENCIA_IMPORTE_ARS', 'ERROR_CONFIRMADO', 'Diferencia original×cotización vs ARS almacenado supera tolerancia.');
                }
                if ($clasificaciones === []) {
                    $push('OK', 'OK', 'Sin observaciones.');
                }

                $registro = [
                    'viaje' => $viaje['numero'] ?? $viajeId,
                    'viaje_id' => $viajeId,
                    'estado_viaje' => $estadoViaje,
                    'estado_registro' => $estadoRegistro,
                    'empresa' => $empresa['razon_social'] ?? ($empresa['nombre_fantasia'] ?? $empresaId),
                    'empresa_id' => $empresaId,
                    'sucursal_id' => (string) ($row['sucursal_id'] ?? ($viaje['sucursal_id'] ?? '')),
                    'tipo_movimiento' => $part['tipo'],
                    'tipo_registro' => null,
                    'circuito' => $part['label'],
                    'movimiento_id' => $row['id'] ?? null,
                    'tabla' => 'viaje_operativo_tramos',
                    'fecha' => $row['fecha_carga'] ?? $row['fecha_descarga'] ?? $row['created_at'] ?? null,
                    'camion' => $tripTruck[$viajeId] ?? null,
                    'chofer' => $tripDriver[$viajeId] ?? null,
                    'importe_original' => $amount,
                    'moneda' => $currency !== '' ? $currency : null,
                    'cotizacion_registrada' => $rate,
                    'cotizacion_vigente_catalogo' => $catalogRate,
                    'importe_ars_registrado' => $arsStored,
                    'importe_ars_esperado' => $expectedArs,
                    'diferencia_ars' => $diffArs,
                    'clasificacion' => implode('|', array_unique($clasificaciones)),
                    'severidad' => $severidad,
                    'motivos' => $motivos,
                    'confirmacion_explicita_evidencia' => null,
                    'anulado_at' => $row['anulado_at'] ?? null,
                    'deleted_at' => $row['deleted_at'] ?? null,
                    'observaciones' => $row['observaciones'] ?? null,
                    'comprobante' => null,
                    'pagado_por' => null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                ];

                $todosMovimientos[] = $registro;
                $resumen['total_movimientos_auditados']++;
                $resumen['distribucion_circuito'][$part['label']] = ($resumen['distribucion_circuito'][$part['label']] ?? 0) + 1;
                $resumen['distribucion_estado_viaje'][$estadoViaje !== '' ? $estadoViaje : 'SIN_ESTADO'] = ($resumen['distribucion_estado_viaje'][$estadoViaje !== '' ? $estadoViaje : 'SIN_ESTADO'] ?? 0) + 1;
                foreach (array_unique($clasificaciones) as $c) {
                    $resumen['distribucion_clasificacion'][$c] = ($resumen['distribucion_clasificacion'][$c] ?? 0) + 1;
                }
                if ($severidad === 'OK') {
                    $resumen['total_ok']++;
                } else {
                    $resumen['viajes_afectados'][$viajeId] = $viaje['numero'] ?? $viajeId;
                    if ($severidad === 'SOSPECHOSO') {
                        $resumen['total_sospechosos']++;
                        if ($currency !== 'ARS' && $currency !== '' && moneyEqual($rate, 1.0, 0.0000005) && $catalogRate !== null && $amount !== null) {
                            $resumen['impacto_estimado_sospechoso_ars'] += abs(($amount * $catalogRate) - ($arsStored ?? 0.0));
                        } elseif ($diffAbs !== null) {
                            $resumen['impacto_estimado_sospechoso_ars'] += $diffAbs;
                        }
                    } elseif ($severidad === 'ERROR_CONFIRMADO') {
                        $resumen['total_error_confirmado']++;
                    } elseif ($severidad === 'REVISION_ADMINISTRATIVA') {
                        $resumen['total_revision_administrativa']++;
                    }
                    $observaciones[] = $registro;
                }
            }
        }
    }
}

$resumen['total_viajes_auditados'] = count($tripIdsSeen);
$resumen['viajes_afectados'] = array_values($resumen['viajes_afectados']);
$resumen['impacto_estimado_sospechoso_ars'] = round($resumen['impacto_estimado_sospechoso_ars'], 2);

// Diagnóstico específico viaje 28 (todos los movimientos, incluso OK)
$trip28 = array_values(array_filter($todosMovimientos, static fn (array $r): bool => (string) ($r['viaje'] ?? '') === '28' || (string) ($r['viaje_id'] ?? '') === '28'));

if ($soloLectura) {
    try {
        $db->query('ROLLBACK');
    } catch (Throwable) {
    }
}

echo json_encode([
    'ok' => true,
    'issue' => 591,
    'generado_en' => date('Y-m-d H:i:s'),
    'base' => $env['database.default.database'] ?? null,
    'transaccion_solo_lectura' => $soloLectura,
    'nota_transaccion' => $notaTransaccion,
    'resumen' => $resumen,
    'observaciones' => $observaciones,
    'trip_28_todos_movimientos' => $trip28,
    'avisos_php' => $avisosPhp,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
