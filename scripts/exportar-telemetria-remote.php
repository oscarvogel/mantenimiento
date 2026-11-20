<?php

declare(strict_types=1);

/**
 * Exportacion de solo lectura de la telemetria (Wialon) y su contexto de
 * mantenimiento, para analisis fuera de linea (alertas e informes de gerencia).
 *
 * - Solo hace SELECT, dentro de una sesion READ ONLY.
 * - Requiere el header X-Migrate-Token (igual que los demas helpers) y corre
 *   solo en produccion.
 * - Se niega a correr si el archivo tiene mas de 3 horas en el servidor.
 * - Devuelve un ZIP con un CSV por tabla y un manifest.json con filas y SHA-256.
 * - No exporta usuarios, empleados, clientes ni el token de la integracion.
 *
 * Parametros GET: empresa_id (obligatorio), dias (default 90),
 * crudos=1 para incluir mensajes_fuente_json de telematia_buckets (pesado).
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

set_time_limit(0);
date_default_timezone_set('America/Argentina/Buenos_Aires');

$fallar = static function (int $codigo, string $mensaje): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit(1);
};

// Vencimiento: el helper no debe quedar olvidado en el servidor.
if (! $isCli && (time() - (int) filemtime(__FILE__)) > 3 * 3600) {
    $fallar(410, 'El helper vencio (mas de 3 h en el servidor). Borrarlo y volver a subirlo.');
}

$empresaId = (int) ($_GET['empresa_id'] ?? 0);
if ($empresaId <= 0) {
    $fallar(400, 'Falta empresa_id.');
}
$dias = max(1, min(400, (int) ($_GET['dias'] ?? 90)));
$incluirCrudos = (string) ($_GET['crudos'] ?? '') === '1';
$desde = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->sub(new DateInterval('P' . ($dias + 1) . 'D'))
    ->format('Y-m-d H:i:s');

if (! class_exists(ZipArchive::class)) {
    $fallar(500, 'La extension zip de PHP no esta disponible.');
}

/*
 * Tablas a exportar.
 *  fecha:   columna para recortar a los ultimos N dias (null = todo el historial).
 *  excluir: columnas que nunca salen.
 *  global:  la tabla no tiene empresa_id y es un catalogo inofensivo.
 */
$tablas = [
    // Telemetria
    'telematia_muestras'        => ['fecha' => 'observada_en'],
    'telematia_buckets'         => ['fecha' => 'bucket_inicio_utc', 'excluir' => $incluirCrudos ? [] : ['mensajes_fuente_json']],
    'telematia_ultima_lectura'  => [],
    'telematia_cursor'          => [],
    'equipo_telemetria'         => [],
    'integraciones_telemetria'  => ['excluir' => ['token_cifrado', 'endpoint']],
    'telemetria_consumo_config' => [],
    // Flota
    'equipos'                   => [],
    'tipos_equipo'              => ['global' => true],
    'marcas'                    => [],
    'modelos'                   => [],
    'sucursales'                => ['excluir' => ['email_alertas', 'direccion']],
    'equipo_sucursal_historial' => [],
    'equipo_relaciones'         => [],
    // Mantenimiento
    'tipos_servicio'            => [],
    'planes_mantenimiento'      => [],
    'avisos_plan'               => [],
    'ordenes_trabajo'           => [],
    'orden_tareas'              => [],
    'orden_repuestos'           => [],
    'lecturas_equipo'           => [],
    // Viajes (para cruzar km, horas y combustible cargado)
    'viajes_operativos'           => [],
    'viaje_operativo_asignaciones' => [],
    'viaje_operativo_tramos'      => [],
    'viaje_operativo_paradas'     => [],
    'viaje_operativo_combustible' => [],
];

try {
    $db = db_connect();
    $db->query('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable $error) {
    $fallar(500, 'No se pudo conectar a la base: ' . $error->getMessage());
}

$base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
if (! is_writable($base)) {
    $base = rtrim(WRITEPATH, DIRECTORY_SEPARATOR);
}
$trabajo = $base . DIRECTORY_SEPARATOR . 'exp-telemetria-' . bin2hex(random_bytes(6));
if (! mkdir($trabajo, 0700)) {
    $fallar(500, 'No se pudo crear el directorio temporal.');
}

$limpiar = static function () use ($trabajo): void {
    foreach (glob($trabajo . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
        @unlink($archivo);
    }
    @rmdir($trabajo);
};

$manifest = [
    'generado_en'   => date(DATE_ATOM),
    'empresa_id'    => $empresaId,
    'dias'          => $dias,
    'desde_utc'     => $desde,
    'crudos'        => $incluirCrudos,
    'nulos'         => 'Los NULL se exportan como celda vacia.',
    'tablas'        => [],
];

try {
    $existentes = array_flip($db->listTables());

    foreach ($tablas as $tabla => $cfg) {
        if (! isset($existentes[$tabla])) {
            $manifest['tablas'][$tabla] = ['estado' => 'NO_EXISTE'];
            continue;
        }

        $campos = $db->getFieldNames($tabla);
        $tieneEmpresa = in_array('empresa_id', $campos, true);
        if (! $tieneEmpresa && empty($cfg['global'])) {
            $manifest['tablas'][$tabla] = ['estado' => 'OMITIDA_SIN_EMPRESA'];
            continue;
        }

        $columnas = array_values(array_diff($campos, $cfg['excluir'] ?? []));
        $fecha = $cfg['fecha'] ?? null;
        if ($fecha !== null && ! in_array($fecha, $campos, true)) {
            $fecha = null;
        }
        $tieneId = in_array('id', $campos, true);

        $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columnas));
        $where = [];
        $binds = [];
        if ($tieneEmpresa) {
            $where[] = '`empresa_id` = ?';
            $binds[] = $empresaId;
        }
        if ($fecha !== null) {
            $where[] = '`' . $fecha . '` >= ?';
            $binds[] = $desde;
        }

        $ruta = $trabajo . DIRECTORY_SEPARATOR . $tabla . '.csv';
        $salida = fopen($ruta, 'wb');
        fputcsv($salida, $columnas, ',', '"', '\\');
        $filas = 0;

        $ultimoId = 0;
        do {
            $condiciones = $where;
            $parametros = $binds;
            $sufijo = '';
            if ($tieneId) {
                $condiciones[] = '`id` > ?';
                $parametros[] = $ultimoId;
                $sufijo = ' ORDER BY `id` LIMIT 5000';
            }
            $sql = 'SELECT ' . $select . ' FROM `' . $tabla . '`'
                . ($condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones))
                . $sufijo;

            $lote = $db->query($sql, $parametros)->getResultArray();
            foreach ($lote as $fila) {
                fputcsv($salida, array_map(
                    static fn ($v) => $v === null ? '' : (string) $v,
                    array_values($fila)
                ), ',', '"', '\\');
                $filas++;
                if ($tieneId) {
                    $ultimoId = (int) $fila['id'];
                }
            }
        } while ($tieneId && count($lote) === 5000);

        fclose($salida);
        $manifest['tablas'][$tabla] = [
            'estado'   => 'OK',
            'filas'    => $filas,
            'filtro'   => $fecha !== null ? $fecha . ' >= ' . $desde : 'historial completo',
            'excluidas' => array_values($cfg['excluir'] ?? []),
            'sha256'   => hash_file('sha256', $ruta),
            'bytes'    => filesize($ruta),
        ];
    }

    file_put_contents(
        $trabajo . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $zipRuta = $trabajo . DIRECTORY_SEPARATOR . 'telemetria.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo crear el ZIP.');
    }
    foreach (glob($trabajo . DIRECTORY_SEPARATOR . '*.{csv,json}', GLOB_BRACE) ?: [] as $archivo) {
        $zip->addFile($archivo, basename($archivo));
    }
    $zip->close();
} catch (Throwable $error) {
    $limpiar();
    $fallar(500, get_class($error) . ': ' . $error->getMessage());
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="telemetria-empresa' . $empresaId . '-' . date('Ymd-His') . '.zip"');
header('Content-Length: ' . filesize($zipRuta));
header('X-Export-Sha256: ' . hash_file('sha256', $zipRuta));
header('Cache-Control: no-store');
readfile($zipRuta);
$limpiar();
exit(0);
