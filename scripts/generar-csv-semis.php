<?php

declare(strict_types=1);

/**
 * Genera los CSV de carga de los semirremolques de TSA a partir del documento
 * `docs/SEMI.docx` (texto plano en `docs/_semi_texto_extraido.txt`).
 *
 * El archivo sale con los encabezados que declara el propio importador
 * (`ImportType::EQUIPOS->templateHeaders()`), de modo que si el contrato de la
 * importación cambia, este script deja de generar un archivo importable y
 * falla en vez de producir un CSV que se rechaza en la previsualización.
 *
 * Uso:
 *   php scripts/generar-csv-semis.php
 *   php scripts/generar-csv-semis.php --tipo="Semirremolque" --fecha-alta=2026-10-25
 *   php scripts/generar-csv-semis.php --salida=output/carga_semis
 *
 * Salida:
 *   <salida>/semis_argentina.csv   14 semirremolques de TSA Argentina
 *   <salida>/semis_brasil.csv       13 carretas de TSA Brasil
 *
 * Contexto: `docs/PLAN_SEMIS_ACOPLADOS_Y_EQUIPOS_FRIGORIFICOS.md`.
 */

use App\Domain\Importations\ImportType;

/**
 * Chasis que el documento escribe con espacios y que el plan de carga deja
 * pendientes de confirmar con el cliente (riesgo R3 del plan).
 *
 * La carga usa el texto del documento tal cual, sin inventar letras, y deja
 * la pregunta escrita en las observaciones de la ficha.
 */
const CHASIS_PENDIENTES = [
    'AE611RN' => '8B8GSRA3CMM009053',
];

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$options = parseOptions($argv);
$sourcePath = $options['origen'] ?? $root . '/docs/_semi_texto_extraido.txt';
$outputDir = $options['salida'] ?? $root . '/docs/carga_semis';
$equipmentType = $options['tipo'] ?? 'Semirremolque';
$registeredAt = $options['fecha-alta'] ?? date('Y-m-d');

if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
    fwrite(STDERR, "No se pudo leer el texto plano del documento: {$sourcePath}\n");
    fwrite(STDERR, "Se extrae desde docs/SEMI.docx (ver docs/PLAN_SEMIS_ACOPLADOS_Y_EQUIPOS_FRIGORIFICOS.md).\n");
    exit(1);
}

if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $registeredAt)) {
    fwrite(STDERR, "La fecha de alta debe tener formato AAAA-MM-DD. Recibido: {$registeredAt}\n");
    exit(1);
}

$text = (string) file_get_contents($sourcePath);
$units = parseUnits($text);

if ($units === []) {
    fwrite(STDERR, "No se pudo interpretar ninguna unidad del documento.\n");
    exit(1);
}

$groups = [
    'TSAARG' => [],
    'TSABR' => [],
];
foreach ($units as $unit) {
    $groups[$unit['sucursal_codigo']][] = $unit;
}

$headers = ImportType::EQUIPOS->templateHeaders();

if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "No se pudo crear el directorio de salida: {$outputDir}\n");
    exit(1);
}

$written = [];
foreach ($groups as $branchCode => $rows) {
    if ($rows === []) {
        continue;
    }
    $file = $outputDir . '/semis_' . ($branchCode === 'TSAARG' ? 'argentina' : 'brasil') . '.csv';
    writeCsv($file, $headers, $rows, $equipmentType, $registeredAt);
    $written[] = [$file, count($rows)];
}

echo "Unidades interpretadas: " . count($units) . "\n";
echo str_repeat('-', 78) . "\n";
printf("%-9s %-6s %-19s %-10s %-14s %-38s %s\n", 'PATENTE', 'AÑO', 'CHASIS', 'MARCA', 'CAJA', 'PÓLIZA', 'SUCURSAL');
echo str_repeat('-', 78) . "\n";
foreach ($units as $unit) {
    printf(
        "%-9s %-6s %-19s %-10s %-14s %-38s %s\n",
        $unit['patente'],
        $unit['anio'],
        $unit['chasis'],
        $unit['marca'],
        $unit['caja'],
        ($unit['policia'] ?? 'sin póliza') . ' ' . ($unit['vencimiento'] ?? ''),
        $unit['sucursal_codigo']
    );
}
echo str_repeat('-', 78) . "\n";
foreach ($written as [$file, $count]) {
    echo "Escrito: {$file} ({$count} filas)\n";
}

/**
 * Interpreta el documento en unidades. Falla si alguna unidad no completa
 * patente, año y chasis: un archivo incompleto es peor que no tener archivo.
 *
 * @return list<array{sucursal_codigo:string,patente:string,anio:string,chasis:string,marca:string,caja:string,equipo_frio:?string,medidas:?string,policia:?string,vencimiento:?string,observaciones:list<string>}>
 */
function parseUnits(string $text): array
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $blocks = [];
    $current = [];
    $group = null;

    foreach ($lines as $line) {
        $line = normalizeText($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^BRASILEROS\b/u', $line) === 1) {
            if ($current !== []) {
                $blocks[] = ['group' => $group, 'lines' => $current];
                $current = [];
            }
            $group = 'TSABR';
            continue;
        }
        if (preg_match('/^SEMI\b(?!RREMOLQUE)/u', $line) === 1) {
            if ($current !== []) {
                $blocks[] = ['group' => $group, 'lines' => $current];
                $current = [];
            }
            $group = 'TSAARG';
            continue;
        }
        if (preg_match('/^(SEMIRREMOLQUE|CARRETA|SIDER)\b/u', $line) === 1) {
            if ($current !== []) {
                $blocks[] = ['group' => $group, 'lines' => $current];
            }
            $current = [$line];
            continue;
        }
        $current[] = $line;
    }
    if ($current !== []) {
        $blocks[] = ['group' => $group, 'lines' => $current];
    }

    $units = [];
    $errors = [];
    foreach ($blocks as $block) {
        $blockText = implode(' | ', $block['lines']);
        $plate = findPlate($blockText);
        $year = findYear($blockText);
        $chassis = findChassis($blockText);

        if ($plate === null || $year === null || $chassis === null) {
            $errors[] = sprintf(
                'Unidad incompleta (%s / %s / %s): %s',
                $plate ?? 'sin patente',
                $year ?? 'sin año',
                $chassis['valor'] !== '' ? $chassis['valor'] : 'sin chasis',
                $blockText
            );
            continue;
        }

        $isBrazil = $block['group'] === 'TSABR';
        $branch = $isBrazil ? 'TSABR' : 'TSAARG';
        $brand = $isBrazil
            ? findBrand($blockText, $plate)
            : findArgentinaBrand($blockText);
        $isFridge = preg_match('/FRIGOR/u', $blockText) === 1;
        $coldUnit = findColdUnitBrand($blockText);
        $measures = findMeasures($blockText);
        $policy = findPolicy($blockText);
        $expiry = findExpiry($blockText);

        $notes = ['Fuente: SEMI.docx (listado de semirremolques de TSA).'];
        if ($policy !== null) {
            $notes[] = 'Póliza ' . $policy . ($expiry !== null ? ' - vto ' . $expiry : '') . '.';
        }
        $notes[] = $isFridge
            ? 'Caja frigorífica' . ($coldUnit !== null ? ' (equipo de frío ' . $coldUnit . ')' : '') . '.'
            : 'Caja sider.';
        if ($measures !== null) {
            $notes[] = 'Medidas (según documento): ' . $measures . '.';
        }
        if ($brand['nota'] !== null) {
            $notes[] = $brand['nota'];
        }
        if ($chassis['normalizado']) {
            $notes[] = 'Chasis normalizado sin espacios (documento: ' . $chassis['original'] . ').';
        }
        if (isset(CHASIS_PENDIENTES[$plate])) {
            $notes[] = 'El plan de carga propone ' . CHASIS_PENDIENTES[$plate]
                . ' para este chasis. Pendiente de confirmar con el cliente.';
        }

        $units[] = [
            'sucursal_codigo' => $branch,
            'patente' => $plate,
            'anio' => $year,
            'chasis' => $chassis['valor'],
            'marca' => $brand['nombre'],
            'caja' => $isFridge ? 'frigorífica' : 'sider',
            'equipo_frio' => $coldUnit,
            'medidas' => $measures,
            'policia' => $policy,
            'vencimiento' => $expiry,
            'observaciones' => $notes,
        ];
    }

    if ($errors !== []) {
        fwrite(STDERR, "El documento tiene unidades que no se pudieron interpretar:\n");
        foreach ($errors as $error) {
            fwrite(STDERR, '  - ' . $error . "\n");
        }
        exit(1);
    }

    return $units;
}

/** @return array{valor:string,original:string,normalizado:bool} */
function findChassis(string $text): array
{
    foreach (explode(' | ', $text) as $line) {
        if (preg_match('/CHAS{1,2}IS\b/u', $line) !== 1) {
            continue;
        }
        // El chasis suele venir en la misma línea que las medidas o el equipo
        // de frío: se corta todo lo que no es el chasis antes de buscarlo.
        $clean = preg_replace('/\b(MEDIDAS|CUMPRIMENTO|ALTURA|ANCHURA|LARGURA|ANCHO|MARCA)\b.*$/u', ' ', $line) ?? $line;
        $clean = preg_replace('/NRO\.?\s*CHASIS:?/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\bCHAS{1,2}IS\b/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/N[°º]\s*:?/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/[^A-Z0-9 ]+/u', ' ', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);

        preg_match_all('/[0-9A-Z]{2,}/u', $clean, $matches);
        $tokens = $matches[0];

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 15 && mb_strlen($token) <= 20) {
                return [
                    'valor' => $token,
                    'original' => $token,
                    'normalizado' => false,
                ];
            }
        }

        // Chasis escritos con espacios (por ejemplo "8B8C SRA 3CM 009053").
        $accumulated = '';
        $parts = [];
        foreach ($tokens as $token) {
            $accumulated .= $token;
            $parts[] = $token;
            if (mb_strlen($accumulated) >= 15 && mb_strlen($accumulated) <= 20) {
                return [
                    'valor' => $accumulated,
                    'original' => implode(' ', $parts),
                    'normalizado' => true,
                ];
            }
            if (mb_strlen($accumulated) > 20) {
                break;
            }
        }
    }

    return ['valor' => '', 'original' => '', 'normalizado' => false];
}

function findPlate(string $text): ?string
{
    foreach (explode(' | ', $text) as $line) {
        if (preg_match('/(?:PATENTE|PLACA)\b/u', $line) !== 1) {
            continue;
        }
        preg_match_all('/[A-Z0-9]{4,}/u', $line, $matches);
        foreach ($matches[0] as $candidate) {
            if (looksLikePlate($candidate)) {
                return $candidate;
            }
        }
    }
    foreach (explode(' | ', $text) as $line) {
        preg_match_all('/[A-Z0-9]{4,}/u', $line, $matches);
        foreach ($matches[0] as $candidate) {
            if (looksLikePlate($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

function looksLikePlate(string $candidate): bool
{
    if (mb_strlen($candidate) < 6 || mb_strlen($candidate) > 8) {
        return false;
    }
    if (preg_match('/\d/u', $candidate) !== 1) {
        return false;
    }
    if (preg_match('/^\d{4}$/u', $candidate) === 1) {
        return false;
    }

    return true;
}

function findYear(string $text): ?string
{
    if (preg_match('/A[NÑ]?O\s*:?\s*(\d{4})/u', $text, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

/**
 * Los 14 semirremolques argentinos son Vulcano. El documento sólo lo dice en
 * la primera unidad; para las otras 13 la marca está inferida del WMI del
 * chasis (8B8GSRA3 / 8C9ST1553), que es el riesgo R2 del plan de carga y
 * queda anotado en las observaciones de cada ficha.
 *
 * @return array{nombre:string,nota:?string}
 */
function findArgentinaBrand(string $text): array
{
    if (preg_match('/VULCANO/u', $text) === 1) {
        return [
            'nombre' => 'Vulcano',
            'nota' => 'Marca Vulcano (informada en el documento).',
        ];
    }

    return [
        'nombre' => 'Vulcano',
        'nota' => 'Marca Vulcano inferida del WMI del chasis; el documento no la informa. Confirmar con el cliente.',
    ];
}

/**
 * La marca sale de la línea donde está la placa cuando el documento menciona
 * más de una. Es lo que resuelve IYQ3E75 (la línea dice "CARRETA SIDER MARCA:
 * FACCHINI" pero la placa dice "PLACA SIDER RANDON"), que el plan de carga
 * asignó a Randon.
 *
 * @return array{nombre:string,nota:?string}
 */
function findBrand(string $text, string $plate): array
{
    $lines = explode(' | ', $text);
    $plateLine = null;
    foreach ($lines as $line) {
        if (preg_match('/(?:PATENTE|PLACA)\b/u', $line) === 1) {
            $plateLine = $line;
            break;
        }
    }

    $candidates = [];
    if ($plateLine !== null) {
        foreach (detectBrands($plateLine) as $brand) {
            $candidates[$brand] = true;
        }
    }
    foreach (detectBrands($text) as $brand) {
        $candidates[$brand] = true;
    }

    if ($candidates === []) {
        return ['nombre' => '', 'nota' => null];
    }

    $names = array_keys($candidates);
    $preferred = $names[0];
    if ($plateLine !== null) {
        foreach (detectBrands($plateLine) as $brand) {
            $preferred = $brand;
            break;
        }
    }

    $nota = null;
    if (count($names) > 1) {
        $nota = 'El documento menciona ' . implode(' y ', $names) . ' para esta unidad; se carga ' . $preferred
            . ' según el plan de carga. Confirmar con el cliente.';
    } elseif ($preferred === 'Vulcano') {
        $nota = 'Marca Vulcano (informada en el documento).';
    } else {
        $nota = 'Marca ' . $preferred . ' (informada en el documento).';
    }

    return ['nombre' => $preferred, 'nota' => $nota];
}

/** @return list<string> */
function detectBrands(string $text): array
{
    $found = [];
    foreach (['Vulcano' => '/VULCANO/u', 'Facchini' => '/FACCHINI/u', 'Randon' => '/RANDON/u'] as $name => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $found[] = $name;
        }
    }

    return $found;
}

function findColdUnitBrand(string $text): ?string
{
    if (preg_match('/MARCA\s+MOTOR\s+(CARRIER|THERMOKING)/u', $text, $matches) === 1) {
        return ucfirst(mb_strtolower($matches[1]));
    }

    return null;
}

function findMeasures(string $text): ?string
{
    foreach (explode(' | ', $text) as $line) {
        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:mts|mt)\b/u', $line) !== 1) {
            continue;
        }
        if (preg_match('/CHAS{1,2}IS/u', $line) === 1 && preg_match('/MEDIDAS/u', $line) !== 1) {
            continue;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function findPolicy(string $text): ?string
{
    $pattern = '/P[ÓO]L[IÍ]ZA\s+(?:NACI[ÓO]N\s+)?(?:SEGURO\s?S?\s*)?(?:N[°º]?\s*:?\s*)?([0-9][0-9.]*)/u';
    if (preg_match($pattern, $text, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

function findExpiry(string $text): ?string
{
    if (preg_match('/(?:VTO|VENC)\s*(\d{2}\/\d{2}\/\d{4})/u', $text, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

function normalizeText(string $line): string
{
    $line = str_replace("\xC2\xA0", ' ', $line);
    $line = mb_strtoupper($line);
    $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

    return trim($line);
}

/**
 * @param list<string> $headers
 * @param list<array<string,mixed>> $rows
 */
function writeCsv(string $file, array $headers, array $rows, string $equipmentType, string $registeredAt): void
{
    $handle = fopen($file, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "No se pudo abrir el archivo para escritura: {$file}\n");
        exit(1);
    }
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $headers);

    foreach ($rows as $row) {
        $line = [
            'sucursal_codigo' => $row['sucursal_codigo'],
            'tipo_equipo' => $equipmentType,
            'codigo' => $row['patente'],
            'patente' => $row['patente'],
            'marca' => $row['marca'],
            'modelo' => '',
            'anio' => $row['anio'],
            'chasis' => $row['chasis'],
            'motor' => '',
            'fecha_alta' => $registeredAt,
            'observaciones' => implode(' ', $row['observaciones']),
        ];
        fputcsv($handle, array_map(static fn (string $column): string => $line[$column], $headers));
    }

    fclose($handle);
}

/** @param list<string> $argv @return array<string,string> */
function parseOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([^=]+)=(.*)$/u', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2];
        }
    }

    return $options;
}
