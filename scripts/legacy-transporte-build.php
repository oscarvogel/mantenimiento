<?php

declare(strict_types=1);

/**
 * Convierte backups `.backup` (pg_dump -Ft) del transporte legacy en un paquete
 * portable que el sistema puede importar desde la web, sin PostgreSQL, sin CLI
 * y sin `pg_restore`.
 *
 * QUE ARCHIVOS TOCA ESTA HERRAMIENTA: solo los `.backup` que se le pasan.
 * Donde se ejecuta: en cualquier maquina con PHP 8.2+ y ext-zip, normalmente la
 * estacion de trabajo o un contenedor. NO se ejecuta en produccion Ferozo,
 * que no tiene shell.
 *
 * QUE NO HACE: no toca la base de datos, no lee `empresa`, no inventa equivalencias.
 * Solo traduce formato y registra un manifiesto verificable.
 *
 * Uso:
 *   php scripts/legacy-transporte-build.php --salida=build/transporte [<archivo.backup> ...]
 *
 * Alcances (`--alcance=`):
 *   viajes   (por defecto) Solo el agregado de Viajes y las tablas maestras que
 *            referencia. Es lo unico que el importador de Viajes sabe consumir.
 *   completo Todas las tablas del dump. Sirve para inspeccion, no para importar.
 *
 * Salida: un `.zip` por backup con `manifest.json` y `data/<tabla>.csv`.
 * Convencion de valores: el texto `\N` representa SQL NULL, igual que en el
 * bloque COPY original, de modo que la transformacion es reversible.
 */

use App\Legacy\Transporte\LegacyBackupReader;

require __DIR__ . '/../app/Legacy/Transporte/LegacyBackupReader.php';

/**
 * Agregado de Viajes y las tablas que sus columnas referencian.
 *
 * `viaje` agrupa el encabezado y sus hijos por la misma clave natural
 * `(cuit_empresa, dominio, numero_viaje)`. `maestra` agrupa los catalogos que
 * esas filas apuntan: sin ellos los viajes quedan huerfanos.
 *
 * @var array<string,string>
 */
const ALCANCE_VIAJES = [
    // agregado del viaje
    't05_viajes' => 'viaje',
    't06_origenes_destinos_por_viaje' => 'viaje',
    't07_cargas_combustible_por_viaje' => 'viaje',
    't08_gastos_por_viaje' => 'viaje',
    't11_cuenta_corriente_x_viaje' => 'viaje',
    't12_cuenta_corriente_origenes_destinos_por_viaje' => 'viaje',
    't13_otros_haberes_por_viaje' => 'viaje',
    // facturacion historica de solo lectura, para analisis de rentabilidad
    'a18_comprobantes_de_venta' => 'facturacion',
    'a19_detalle_comprobantes_de_venta' => 'facturacion',
    // maestras referenciadas por el agregado
    't02_dominios' => 'maestra',
    't10_dominios_por_empresa' => 'maestra',
    't09_empresas' => 'maestra',
    't01_choferes' => 'maestra',
    't03_origen_destino' => 'maestra',
    't04_conceptos_choferes' => 'maestra',
    't18_monedas' => 'maestra',
    't14_cuit_paises' => 'maestra',
];

$rutaScript = $argv[0];
$salida = null;
$alcance = 'viajes';
$soloTablas = [];
$archivos = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--salida=')) {
        $salida = substr($arg, strlen('--salida='));
    } elseif (str_starts_with($arg, '--alcance=')) {
        $alcance = substr($arg, strlen('--alcance='));
    } elseif (str_starts_with($arg, '--tabla=')) {
        $soloTablas[] = substr($arg, strlen('--tabla='));
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Opcion desconocida: $arg\n");
        exit(2);
    } else {
        $archivos[] = $arg;
    }
}

if ($salida === null || $archivos === []) {
    fwrite(STDERR, "Uso: php scripts/legacy-transporte-build.php --salida=DIR [--alcance=viajes|completo] [--tabla=N] <archivo.backup> ...\n");
    exit(2);
}

if ($alcance !== 'viajes' && $alcance !== 'completo') {
    fwrite(STDERR, "Alcance desconocido: $alcance (usar viajes o completo)\n");
    exit(2);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Falta ext-zip en PHP. Habilitala con: php -d extension=zip ...\n");
    exit(2);
}

$version = '1.1.0';
$totalTablas = 0;
$totalFilas = 0;
$salidas = [];

foreach ($archivos as $ruta) {
    $base = basename($ruta);
    $origen = preg_replace('/\.backup$/i', '', $base);
    if ($origen === null || $origen === $base) {
        $origen = pathinfo($base, PATHINFO_FILENAME);
    }

    fwrite(STDERR, ">> $base\n");
    $lector = LegacyBackupReader::fromFile($ruta);

    $tablas = $lector->tablas();

    if ($soloTablas !== []) {
        $tablas = array_values(array_intersect($tablas, $soloTablas));
        $faltantes = array_diff($soloTablas, $tablas);
        foreach ($faltantes as $pedida) {
            fwrite(STDERR, "   aviso: $base no contiene la tabla $pedida\n");
        }
    } elseif ($alcance === 'viajes') {
        // Solo el agregado y sus maestras. Lo demas del dump no lo consume el importador.
        $tablas = array_values(array_intersect($tablas, array_keys(ALCANCE_VIAJES)));
        $ausentes = array_diff(array_keys(ALCANCE_VIAJES), $tablas);
        foreach ($ausentes as $pedida) {
            fwrite(STDERR, "   aviso: $base no contiene la tabla $pedida\n");
        }
    }
    sort($tablas);

    $zipPath = rtrim($salida, '/\\') . DIRECTORY_SEPARATOR . $origen . '-transporte.zip';
    if (!is_dir(dirname($zipPath)) && !mkdir(dirname($zipPath), 0775, true) && !is_dir(dirname($zipPath))) {
        fwrite(STDERR, "No se pudo crear el directorio de salida: " . dirname($zipPath) . "\n");
        exit(3);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "No se pudo abrir el zip: $zipPath\n");
        exit(3);
    }

    $manifiestoTablas = [];
    $filasTotalesArchivo = 0;

    foreach ($tablas as $tabla) {
        $columnas = $lector->columnas($tabla);
        $pk = $lector->primaryKey($tabla);
        $conteo = $lector->contar($tabla);

        $manifiestoTablas[] = [
            'tabla' => $tabla,
            'rol' => ALCANCE_VIAJES[$tabla] ?? 'sin_clasificar',
            'columnas' => $columnas,
            'primary_key' => $pk,
            'filas' => $conteo,
            'csv' => 'data/' . $tabla . '.csv',
        ];
        $filasTotalesArchivo += $conteo;

        // El CSV se arma en un buffer temporal y se agrega al zip como un solo miembro.
        $buffer = fopen('php://temp/maxmemory:2097152', 'r+b');
        if ($buffer === false) {
            fwrite(STDERR, "No se pudo abrir el buffer de escritura\n");
            exit(3);
        }
        fputcsv($buffer, $columnas, ',', '"', '\\');

        foreach ($lector->filas($tabla) as $fila) {
            $linea = [];
            foreach ($columnas as $col) {
                $valor = $fila[$col] ?? null;
                $linea[] = $valor === null ? '\\N' : $valor;
            }
            fputcsv($buffer, $linea, ',', '"', '\\');
        }
        rewind($buffer);
        $zip->addFromString('data/' . $tabla . '.csv', (string) stream_get_contents($buffer));
        fclose($buffer);
    }

    $manifiesto = [
        'formato' => 'legacy-transporte-package',
        'version' => $version,
        'generado_en' => gmdate('c'),
        'alcance' => $alcance === 'viajes' ? 'viajes' : 'completo',
        'origen_archivo' => $base,
        'origen_sha256' => hash_file('sha256', $ruta),
        'tablas' => $manifiestoTablas,
        'resumen' => [
            'tablas' => count($manifiestoTablas),
            'filas' => $filasTotalesArchivo,
            'filas_viaje' => array_sum(array_map(
                static fn (array $t) => $t['rol'] === 'viaje' ? $t['filas'] : 0,
                $manifiestoTablas
            )),
        ],
        'convenciones' => [
            'null' => '\\N',
            'separador' => ',',
            'cita' => '"',
            'encoding' => 'UTF-8',
        ],
    ];

    $zip->addFromString('manifest.json', (string) json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->close();

    $totalTablas += count($manifiestoTablas);
    $totalFilas += $filasTotalesArchivo;
    $salidas[] = $zipPath;

    fwrite(STDERR, "   tablas=" . count($manifiestoTablas) . " filas={$filasTotalesArchivo} -> $zipPath\n");
}

echo json_encode([
    'version' => $version,
    'paquetes' => $salidas,
    'tablas' => $totalTablas,
    'filas' => $totalFilas,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
