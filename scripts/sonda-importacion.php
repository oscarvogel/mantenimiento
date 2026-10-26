<?php

declare(strict_types=1);

/**
 * Sonda de SOLO LECTURA que valida un CSV de importacion contra el catalogo real,
 * usando el MISMO codigo de la aplicacion:
 *
 *   - NativeCsvSpreadsheetReader  (lector real del importador)
 *   - ImportRowValidator          (reglas reales de validacion por fila)
 *   - CodeIgniterImportReferenceGateway (resoluciones contra la base)
 *   - CodeIgniterActorContextProvider   (alcance real de sucursales por usuario)
 *   - CodeIgniterEquipmentFleetLookup  (clasificacion tractor / semirremolque)
 *
 * No escribe nada: todas las consultas son SELECT.
 *
 * Uso en produccion (sin SSH ni CLI):
 *   1. Subir este archivo a la RAIZ del release por FTPS (mismo criterio que
 *      `migrate.php`: vive al lado de index.php).
 *   2. Llamarlo con el header `X-Migrate-Token` (el valor de `MIGRATE_TOKEN` del
 *      `.env` de produccion):
 *        curl -H "X-Migrate-Token: <token>" \
 *             https://vogelconsultoria.com.ar/mantenimiento/sonda-importacion.php
 *      Los CSV se leen de `writable/sonda/` (directorio privado).
 *   3. Borrar el archivo y los CSV por FTPS inmediatamente despues.
 *      `scripts/sonda-produccion.py` hace el ciclo completo (subir, correr, borrar).
 *
 * Uso en staging (con CLI, dentro del contenedor):
 *   php sonda-importacion.php --sonda [--csv-dir=/ruta]
 *
 * En modo HTTP exige `CI_ENVIRONMENT=production` y el token. En modo CLI exige el
 * flag `--sonda`, para que no se pueda disparar por accidente.
 */

use App\Application\Importations\ImportRowValidator;
use App\Application\Importations\StagedImportRow;
use App\Domain\Importations\ImportRowStatus;
use App\Domain\Importations\ImportType;
use App\Infrastructure\Assets\CodeIgniterEquipmentFleetLookup;
use App\Infrastructure\Identity\CodeIgniterActorContextProvider;
use App\Infrastructure\Importations\CodeIgniterImportReferenceGateway;
use App\Infrastructure\Importations\NativeCsvSpreadsheetReader;
use CodeIgniter\Boot;
use Config\Paths;

$isCli = PHP_SAPI === 'cli';
$options = [];

if ($isCli) {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--')) {
            [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '1');
            $options[$key] = $value;
        }
    }

    if (! isset($options['sonda'])) {
        fwrite(STDERR, "Uso: php sonda-importacion.php --sonda [--csv-dir=/ruta]\n");
        exit(2);
    }
}

// 1. Bootstrap minimo: FCPATH + lectura del .env.
//    FCPATH se resuelve subiendo desde la carpeta del script hasta el index.php,
//    para que el archivo funcione tanto en el repo (scripts/) como subido a la raiz.
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
$paths = new Paths();

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

// 2. Bootstrap del framework sin despachar controladores.
require $paths->systemDirectory . '/Boot.php';
(new class extends Boot {
    public static function bootForMigrations(Paths $paths): void
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

if (! $isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$csvDir = $options['csv-dir'] ?? getenv('SONDA_CSV_DIR') ?: (FCPATH . 'writable/sonda');

/**
 * @param list<string> $lines
 */
function emit(array $lines): void
{
    foreach ($lines as $line) {
        echo $line . "\n";
    }
}

echo "=== Sonda de importacion (SOLO LECTURA) ===\n";
echo 'Timestamp (UTC): ' . gmdate('Y-m-d\TH:i:s\Z') . "\n";
echo 'Modo:           ' . ($isCli ? 'CLI' : 'HTTP') . "\n";
echo 'Entorno:        ' . $environment . "\n";
echo 'Release path:   ' . FCPATH . "\n";
echo 'CSV dir:        ' . $csvDir . "\n";

try {
    $db = db_connect();
} catch (Throwable $error) {
    echo 'ERROR: no se pudo conectar a la base: ' . $error->getMessage() . "\n";
    exit(1);
}

echo "\n--- EMPRESAS ---\n";
$empresas = $db->table('empresas')->select('id, razon_social, estado, deleted_at')->get()->getResultArray();
foreach ($empresas as $fila) {
    echo sprintf(
        "  %s | %-40s | estado=%s | deleted=%s\n",
        $fila['id'],
        (string) $fila['razon_social'],
        (string) ($fila['estado'] ?? '?'),
        $fila['deleted_at'] === null ? 'no' : 'si'
    );
}

echo "\n--- TIPOS DE EQUIPO (el NOMBRE define tractor vs semirremolque) ---\n";
$tipos = $db->table('tipos_equipo')->select('id, nombre, activo, controla_km, controla_horas')->orderBy('id', 'ASC')->get()->getResultArray();
$patronAcoplado = '/acoplado|semirremolque|remolque|batea/u';
$patronTractor = '/camion|tractor|chasis|cabina|utilitario|pickup/u';
foreach ($tipos as $fila) {
    $nombre = (string) $fila['nombre'];
    $clase = 'NINGUNO';
    if (preg_match($patronAcoplado, mb_strtolower($nombre)) === 1) {
        $clase = 'SEMIRREMOLQUE';
    } elseif (preg_match($patronTractor, mb_strtolower($nombre)) === 1) {
        $clase = 'TRACTOR';
    }
    echo sprintf(
        "  %s | %-24s | activo=%s | km=%s | horas=%s | classify=%s\n",
        $fila['id'],
        $nombre,
        (string) $fila['activo'],
        (string) $fila['controla_km'],
        (string) $fila['controla_horas'],
        $clase
    );
}

echo "\n--- MARCAS ---\n";
$marcas = $db->table('marcas')->select('id, empresa_id, nombre, activo')->orderBy('empresa_id', 'ASC')->orderBy('nombre', 'ASC')->get()->getResultArray();
if ($marcas === []) {
    echo "  (ninguna)\n";
}
foreach ($marcas as $fila) {
    echo sprintf(
        "  %s | empresa %s | %-22s | activo=%s\n",
        $fila['id'],
        (string) $fila['empresa_id'],
        (string) $fila['nombre'],
        (string) $fila['activo']
    );
}

echo "\n--- SUCURSALES ---\n";
$sucursales = $db->table('sucursales')->select('id, empresa_id, codigo, nombre, estado, deleted_at')->orderBy('empresa_id', 'ASC')->get()->getResultArray();
foreach ($sucursales as $fila) {
    echo sprintf(
        "  %s | empresa %s | %-10s | %-34s | estado=%s | deleted=%s\n",
        $fila['id'],
        (string) $fila['empresa_id'],
        (string) $fila['codigo'],
        (string) $fila['nombre'],
        (string) ($fila['estado'] ?? '?'),
        $fila['deleted_at'] === null ? 'no' : 'si'
    );
}

// Empresa de TSA: se deduce de las sucursales TSAARG / TSABR.
$tsaSucursales = $db->table('sucursales')->select('id, empresa_id, codigo, nombre, estado')->whereIn('codigo', ['TSAARG', 'TSABR'])->get()->getResultArray();
$companyId = null;
foreach ($tsaSucursales as $fila) {
    $companyId ??= (int) $fila['empresa_id'];
}
echo "\n--- EMPRESA TSA ---\n";
if ($companyId === null) {
    echo "  NO ENCONTRADA: no existe ninguna sucursal con codigo TSAARG ni TSABR.\n";
} else {
    echo "  empresa_id = {$companyId}\n";
}

echo "\n--- USUARIOS DE LA EMPRESA TSA (alcance real de sucursales) ---\n";
$provider = new CodeIgniterActorContextProvider();
$usuarios = [];
if ($companyId !== null) {
    $usuarios = $db->table('usuarios')->select('id, nombre, email, activo, es_superadmin')
        ->where('empresa_id', $companyId)->where('activo', 1)->orderBy('id', 'ASC')->get()->getResultArray();
}
if ($usuarios === []) {
    echo "  (ninguno)\n";
}
foreach ($usuarios as $usuario) {
    $actor = $provider->load((int) $usuario['id']);
    if ($actor === null) {
        echo sprintf("  %s | %-24s | actor NO RESUELTO\n", $usuario['id'], (string) $usuario['email']);
        continue;
    }
    $codigos = [];
    foreach ($actor->branchIds() as $branchId) {
        foreach ($tsaSucursales as $sucursal) {
            if ((int) $sucursal['id'] === $branchId) {
                $codigos[] = (string) $sucursal['codigo'];
            }
        }
    }
    echo sprintf(
        "  %s | %-28s | roles=%s | todas_sucursales=%s | sucursales=[%s] | puede_importar=%s\n",
        $usuario['id'],
        (string) $usuario['email'],
        implode(',', $actor->roles()) === '' ? '-' : implode(',', $actor->roles()),
        $actor->hasAllCompanyBranches() ? 'si' : 'no',
        implode(',', $codigos),
        $actor->hasPermission('importaciones.cargar') ? 'si' : 'no'
    );
}

// 3. Archivos a validar.
$files = glob(rtrim($csvDir, '/') . '/*.csv');
if ($files === false || $files === []) {
    echo "\n--- ARCHIVOS ---\n";
    echo "  (no se encontraron CSV en {$csvDir})\n";
    echo "OK: sonda terminada (solo lectura).\n";
    exit(0);
}
sort($files);

echo "\n--- ARCHIVOS A VALIDAR ---\n";
$reader = new NativeCsvSpreadsheetReader();
$type = ImportType::EQUIPOS;
$preparados = [];
foreach ($files as $file) {
    try {
        $sheet = $reader->read($file, 5000);
    } catch (Throwable $error) {
        echo '  ' . basename($file) . ' | ERROR de lectura: ' . $error->getMessage() . "\n";
        continue;
    }
    $faltantes = array_values(array_diff($type->requiredHeaders(), $sheet->headers));
    $repetidos = array_values(array_diff(array_unique($sheet->headers), $sheet->headers));
    echo sprintf(
        "  %-24s | filas=%d | encabezados=%d | faltantes=[%s] | repetidos=[%s]\n",
        basename($file),
        count($sheet->rows),
        count($sheet->headers),
        implode(',', $faltantes),
        implode(',', $repetidos)
    );
    $preparados[] = ['path' => $file, 'sheet' => $sheet];
}

if ($companyId === null || $preparados === []) {
    echo "\nOK: sonda terminada (solo lectura).\n";
    exit(0);
}

// 4. Validacion real, usuario por usuario.
$gateway = new CodeIgniterImportReferenceGateway($db);

echo "\n--- VALIDACION REAL POR USUARIO (ImportRowValidator) ---\n";
foreach ($preparados as $preparado) {
    /** @var \App\Application\Importations\SpreadsheetData $sheet */
    $sheet = $preparado['sheet'];
    echo "\n  >> " . basename($preparado['path']) . "\n";

    foreach ($usuarios as $usuario) {
        $actor = $provider->load((int) $usuario['id']);
        if ($actor === null || ! $actor->hasPermission('importaciones.cargar')) {
            continue;
        }

        $validator = new ImportRowValidator($gateway);
        $validator->beginFile();
        $validas = 0;
        $errores = 0;
        $duplicadas = 0;
        $detalle = [];

        foreach ($sheet->rows as $index => $row) {
            $item = $validator->validate($type, $row, $index + 2, $actor, $companyId);
            if ($item->status === ImportRowStatus::VALIDA) {
                $validas++;
            } elseif ($item->status === ImportRowStatus::ERROR) {
                $errores++;
            } else {
                $duplicadas++;
            }

            if ($item->issues !== []) {
                $lineas = [];
                foreach ($item->issues as $issue) {
                    $lineas[] = sprintf(
                        '[%s] %s=%s :: %s',
                        $issue->severity,
                        $issue->field,
                        $issue->value === null ? '-' : $issue->value,
                        $issue->message
                    );
                }
                $detalle[] = sprintf(
                    "     fila %d (%s) estado=%s :: %s",
                    $item->rowNumber,
                    (string) ($item->source['codigo'] ?? '?'),
                    $item->status->value,
                    implode(' | ', $lineas)
                );
            }
        }

        echo sprintf(
            "     usuario %s (%s): validas=%d errores=%d duplicadas=%d\n",
            $usuario['id'],
            (string) $usuario['email'],
            $validas,
            $errores,
            $duplicadas
        );
        foreach ($detalle as $linea) {
            echo $linea . "\n";
        }
    }
}

// 5. Estado de las 27 patentes en la base.
echo "\n--- PATENTES DEL CSV EN LA BASE ---\n";
$patentes = [];
foreach ($preparados as $preparado) {
    /** @var \App\Application\Importations\SpreadsheetData $sheet */
    $sheet = $preparado['sheet'];
    foreach ($sheet->rows as $row) {
        $patente = mb_strtoupper(trim((string) ($row['patente'] ?? '')));
        if ($patente !== '') {
            $patentes[$patente] = true;
        }
    }
}
$patentes = array_keys($patentes);
$existentes = $db->table('equipos')->select('id, empresa_id, codigo, patente, estado')
    ->whereIn('patente', $patentes)->get()->getResultArray();
echo '  patentes en los CSV: ' . count($patentes) . "\n";
echo '  ya cargadas:         ' . count($existentes) . "\n";
foreach ($existentes as $fila) {
    echo sprintf(
        "    %s | empresa %s | codigo %s | patente %s | estado %s\n",
        $fila['id'],
        (string) $fila['empresa_id'],
        (string) $fila['codigo'],
        (string) $fila['patente'],
        (string) $fila['estado']
    );
}

echo "\n--- COMPARACION FILA A FILA (CSV vs base) ---\n";
$tiposPorId = [];
foreach ($tipos as $fila) {
    $tiposPorId[(int) $fila['id']] = (string) $fila['nombre'];
}
$marcasPorId = [];
foreach ($marcas as $fila) {
    $marcasPorId[(int) $fila['id']] = (string) $fila['nombre'];
}
$sucursalesPorId = [];
foreach ($sucursales as $fila) {
    $sucursalesPorId[(int) $fila['id']] = (string) $fila['codigo'];
}

$coincidencias = 0;
$conDiferencias = 0;
$noCargadas = 0;

foreach ($preparados as $preparado) {
    /** @var \App\Application\Importations\SpreadsheetData $sheet */
    $sheet = $preparado['sheet'];
    echo "\n  >> " . basename($preparado['path']) . "\n";

    foreach ($sheet->rows as $row) {
        $codigo = mb_strtoupper(trim((string) ($row['codigo'] ?? '')));
        $equipo = $db->table('equipos e')
            ->select('e.id, e.codigo, e.patente, e.anio, e.chasis, e.motor, e.fecha_alta, e.observaciones, e.estado, e.sucursal_id, e.tipo_equipo_id, e.marca_id')
            ->where('e.empresa_id', $companyId)
            ->where('e.codigo', $codigo)
            ->where('e.deleted_at', null)
            ->get()->getRowArray();

        if ($equipo === null) {
            echo "     NO_CARGADO  {$codigo}\n";
            $noCargadas++;
            continue;
        }

        $esperado = [
            'patente' => mb_strtoupper(trim((string) ($row['patente'] ?? ''))),
            'tipo' => trim((string) ($row['tipo_equipo'] ?? '')),
            'marca' => trim((string) ($row['marca'] ?? '')),
            'sucursal' => mb_strtoupper(trim((string) ($row['sucursal_codigo'] ?? ''))),
            'anio' => trim((string) ($row['anio'] ?? '')),
            'chasis' => mb_strtoupper(trim((string) ($row['chasis'] ?? ''))),
            'motor' => mb_strtoupper(trim((string) ($row['motor'] ?? ''))),
            'fecha_alta' => trim((string) ($row['fecha_alta'] ?? '')),
            'observaciones' => trim((string) ($row['observaciones'] ?? '')),
        ];

        $actual = [
            'patente' => mb_strtoupper(trim((string) ($equipo['patente'] ?? ''))),
            'tipo' => $tiposPorId[(int) $equipo['tipo_equipo_id']] ?? '?',
            'marca' => $equipo['marca_id'] === null ? '' : ($marcasPorId[(int) $equipo['marca_id']] ?? '?'),
            'sucursal' => $sucursalesPorId[(int) $equipo['sucursal_id']] ?? '?',
            'anio' => (string) ($equipo['anio'] ?? ''),
            'chasis' => mb_strtoupper(trim((string) ($equipo['chasis'] ?? ''))),
            'motor' => mb_strtoupper(trim((string) ($equipo['motor'] ?? ''))),
            'fecha_alta' => (string) ($equipo['fecha_alta'] ?? ''),
            'observaciones' => trim((string) ($equipo['observaciones'] ?? '')),
        ];

        $diferencias = [];
        foreach ($esperado as $campo => $valor) {
            $enBase = $actual[$campo];
            $iguales = in_array($campo, ['tipo', 'marca'], true)
                ? mb_strtolower($valor) === mb_strtolower($enBase)
                : $valor === $enBase;
            if (! $iguales) {
                $diferencias[] = sprintf(
                    '%s: CSV="%s" base="%s"',
                    $campo,
                    mb_strimwidth($valor, 0, 60, '...'),
                    mb_strimwidth($enBase, 0, 60, '...')
                );
            }
        }

        if ($diferencias === []) {
            echo sprintf(
                "     OK          %-9s | id=%s | estado=%s | %s | %s | %s\n",
                $codigo,
                (string) $equipo['id'],
                (string) $equipo['estado'],
                $actual['tipo'],
                $actual['marca'],
                $actual['sucursal']
            );
            $coincidencias++;
            continue;
        }

        echo sprintf("     DIFERENCIA  %-9s | id=%s\n", $codigo, (string) $equipo['id']);
        foreach ($diferencias as $detalle) {
            echo '       - ' . $detalle . "\n";
        }
        $conDiferencias++;
    }
}

echo sprintf(
    "\n  RESUMEN: %d coinciden | %d con diferencias | %d no cargadas\n",
    $coincidencias,
    $conDiferencias,
    $noCargadas
);

echo "\n--- CLASIFICACION DE FLOTA (lo que ve el formulario de viajes) ---\n";
$fleet = new CodeIgniterEquipmentFleetLookup($db);
$idsCarga = [];
foreach ($preparados as $preparado) {
    /** @var \App\Application\Importations\SpreadsheetData $sheet */
    $sheet = $preparado['sheet'];
    foreach ($sheet->rows as $row) {
        $codigo = mb_strtoupper(trim((string) ($row['codigo'] ?? '')));
        $equipo = $db->table('equipos')->select('id')->where('empresa_id', $companyId)
            ->where('codigo', $codigo)->where('deleted_at', null)->get()->getRowArray();
        if ($equipo !== null) {
            $idsCarga[] = (int) $equipo['id'];
        }
    }
}

$comoCaja = 0;
$comoTractor = 0;
$sinClasificar = 0;
foreach ($idsCarga as $id) {
    $item = $fleet->equipment($companyId, $id);
    if ($item === null) {
        $sinClasificar++;
        continue;
    }
    if ($item['trailer']) {
        $comoCaja++;
    } elseif ($item['tractor']) {
        $comoTractor++;
    } else {
        $sinClasificar++;
    }
}
echo sprintf(
    "  las %d semirremolques cargadas: %d como SEMIRREMOLQUE | %d como TRACTOR | %d sin clasificar\n",
    count($idsCarga),
    $comoCaja,
    $comoTractor,
    $sinClasificar
);

$tractores = $db->table('equipos e')->select('e.id, e.codigo, e.patente, t.nombre AS tipo')
    ->join('tipos_equipo t', 't.id = e.tipo_equipo_id')
    ->where('e.empresa_id', $companyId)->where('e.estado', 'ACTIVO')->where('e.deleted_at', null)
    ->get()->getResultArray();
$tractoresDetectados = [];
foreach ($tractores as $fila) {
    $item = $fleet->equipment($companyId, (int) $fila['id']);
    if ($item !== null && $item['tractor']) {
        $tractoresDetectados[] = (int) $fila['id'];
    }
}
echo "  tractores activos de la empresa: " . count($tractoresDetectados) . "\n";

$conCaja = $fleet->activeTrailers($companyId, $tractoresDetectados);
$totalCajasAsignadas = 0;
foreach ($conCaja as $cajas) {
    $totalCajasAsignadas += count($cajas);
}
echo "  tractores que ya tienen semirremolque asignado: " . count($conCaja) . "\n";
echo "  semirremolques ya enganchados a algun tractor: " . $totalCajasAsignadas . "\n";

echo "\n--- RELACIONES TRACTOR-ACOPLADO VIGENTES ---\n";
$relaciones = $db->table('equipo_relaciones')
    ->select('id, empresa_id, equipo_principal_id, equipo_relacionado_id, tipo_relacion, desde, hasta')
    ->where('tipo_relacion', 'TRACTOR_ACOPLADO')->get()->getResultArray();
echo '  total: ' . count($relaciones) . "\n";
foreach ($relaciones as $fila) {
    echo sprintf(
        "    %s | empresa %s | principal %s -> relacionado %s | desde %s | hasta %s\n",
        $fila['id'],
        (string) $fila['empresa_id'],
        (string) $fila['equipo_principal_id'],
        (string) $fila['equipo_relacionado_id'],
        (string) $fila['desde'],
        $fila['hasta'] === null ? 'vigente' : (string) $fila['hasta']
    );
}

echo "\nOK: sonda terminada (solo lectura).\n";
