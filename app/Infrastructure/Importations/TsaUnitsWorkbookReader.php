<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\SpreadsheetReader;
use App\Application\Importations\SpreadsheetData;
use DomainException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Anti-Corruption Layer para el listado de unidades entregado por TSA.
 * Las hojas tienen títulos y filas de cabecera propios; nunca se exponen al
 * resto de la aplicación: se traducen al contrato canónico de equipos.
 */
final class TsaUnitsWorkbookReader implements SpreadsheetReader
{
    /** @var list<string> */
    private const HEADERS = [
        'sucursal_codigo', 'tipo_equipo', 'codigo', 'patente', 'marca', 'modelo',
        'anio', 'chasis', 'motor', 'fecha_alta', 'observaciones',
    ];

    public function read(string $privatePath, int $maximumRows = 5000): SpreadsheetData
    {
        $spreadsheet = $this->load($privatePath, 'El archivo de unidades');
        try {
            $rows = [];
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $matrix = $this->matrix($sheet);
                $canonical = $this->canonicalHeader($matrix);
                if ($canonical !== null) {
                    $rows = array_merge($rows, $this->canonicalRows($matrix, $canonical, $maximumRows - count($rows)));
                    continue;
                }

                $header = $this->unitsHeader($matrix);
                if ($header === null) {
                    continue;
                }
                $branch = $this->branchCode($sheet->getTitle());
                if ($branch === null) {
                    throw new DomainException('No se pudo determinar la sucursal de la hoja ' . $sheet->getTitle() . '. Use una hoja de Argentina o Brasil.');
                }
                foreach (array_slice($matrix, $header['row'] + 1) as $values) {
                    $rawDescription = $this->text($values[$header['brand']] ?? null);
                    $plate = $this->plate($values[$header['plate']] ?? null);
                    $driver = $this->text($values[$header['driver']] ?? null);
                    if ($rawDescription === '' && $plate === '') {
                        continue;
                    }
                    if ($plate === null) {
                        continue;
                    }
                    if (count($rows) >= $maximumRows) {
                        throw new DomainException("El archivo supera el limite de {$maximumRows} filas de datos.");
                    }
                    [$brand, $model, $year] = $this->splitDescription($rawDescription);
                    $notes = 'Fuente: listado entregado por TSA.';
                    if ($driver !== '') {
                        $notes .= ' Chofer informado: ' . $driver . '.';
                    }
                    $rows[] = [
                        'sucursal_codigo' => $branch,
                        'tipo_equipo' => 'Camión',
                        'codigo' => $plate,
                        'patente' => $plate,
                        'marca' => $brand,
                        'modelo' => $model,
                        'anio' => $year,
                        'chasis' => null,
                        'motor' => null,
                        'fecha_alta' => date('Y-m-d'),
                        'observaciones' => $notes,
                    ];
                }
            }

            if ($rows === []) {
                throw new DomainException('El XLSX no contiene hojas de unidades reconocibles.');
            }
            return new SpreadsheetData(self::HEADERS, $rows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @return array{row:int,brand:int,plate:int,driver:int}|null */
    private function unitsHeader(array $matrix): ?array
    {
        foreach ($matrix as $row => $values) {
            $normalized = array_map(fn (mixed $value): string => $this->header((string) $value), $values);
            $brand = array_search('marca', $normalized, true);
            $plate = null;
            foreach (['patente_tractor', 'patentes', 'patente'] as $candidate) {
                $plate = array_search($candidate, $normalized, true);
                if ($plate !== false) {
                    break;
                }
            }
            if ($brand !== false && $plate !== false) {
                $driver = array_search('chofer', $normalized, true);
                return ['row' => $row, 'brand' => (int) $brand, 'plate' => (int) $plate, 'driver' => $driver === false ? -1 : (int) $driver];
            }
        }
        return null;
    }

    /** @return array{row:int,headers:list<string>}|null */
    private function canonicalHeader(array $matrix): ?array
    {
        foreach ($matrix as $row => $values) {
            $headers = array_values(array_map(fn (mixed $value): string => $this->header((string) $value), $values));
            if (in_array('sucursal_codigo', $headers, true) && in_array('codigo', $headers, true)) {
                return ['row' => $row, 'headers' => $headers];
            }
        }
        return null;
    }

    /** @param array{row:int,headers:list<string>} $header @return list<array<string,string|null>> */
    private function canonicalRows(array $matrix, array $header, int $remaining): array
    {
        $rows = [];
        foreach (array_slice($matrix, $header['row'] + 1) as $values) {
            if ($this->blank($values)) {
                continue;
            }
            if (count($rows) >= $remaining) {
                throw new DomainException('El archivo supera el limite de filas de datos.');
            }
            $row = [];
            foreach (self::HEADERS as $column) {
                $index = array_search($column, $header['headers'], true);
                $row[$column] = $index === false ? null : $this->text($values[$index] ?? null);
            }
            $row['codigo'] = $this->plate($row['codigo']);
            $row['patente'] = $this->plate($row['patente']);
            if ($row['fecha_alta'] === null || $row['fecha_alta'] === '') {
                $row['fecha_alta'] = date('Y-m-d');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array{brand:string,model:string,year:?string} */
    private function splitDescription(string $description): array
    {
        $value = mb_strtoupper(trim($description));
        $year = null;
        // Un modelo puede llamarse 2035; sólo interpretamos como año el
        // número que la fuente marca explícitamente con AÑO/ANO.
        if (preg_match('/\b(?:AÑO|ANO)\s*((?:19|20)\d{2})\b/iu', $value, $match) === 1) {
            $year = $match[1];
            $value = trim((string) preg_replace('/\b(?:AÑO|ANO)\s*' . preg_quote($year, '/') . '\b/iu', '', $value));
        }

        foreach (['MERCEDES-BENZ', 'MERCEDES BENZ', 'SCANIA', 'VOLVO', 'IVECO', 'RENAULT'] as $knownBrand) {
            if (str_starts_with($value, $knownBrand)) {
                return [$knownBrand === 'MERCEDES BENZ' ? 'MERCEDES-BENZ' : $knownBrand, trim((string) preg_replace('/^' . preg_quote($knownBrand, '/') . '\s*(?:\/\s*)?/u', '', $value)), $year];
            }
        }
        $parts = preg_split('/\s+/u', $value, 2) ?: [];
        return [$parts[0] ?? '', $parts[1] ?? '', $year];
    }

    private function branchCode(string $title): ?string
    {
        $title = mb_strtoupper($title);
        return str_contains($title, 'ARGENTINA') ? 'TSAARG' : (str_contains($title, 'BRASIL') ? 'TSABR' : null);
    }

    private function plate(mixed $value): ?string
    {
        $value = $this->text($value);
        if ($value === '') {
            return null;
        }
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $value));
    }

    private function load(string $path, string $label): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx' || ! is_file($path) || ! str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04")) {
            throw new DomainException($label . ' debe ser un XLSX valido.');
        }
        if (! class_exists(IOFactory::class) || ! class_exists(\ZipArchive::class)) {
            throw new DomainException('No se puede leer XLSX: falta habilitar la extension PHP zip.');
        }
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            return $reader->load($path);
        } catch (\Throwable $exception) {
            throw new DomainException('No se pudo leer el XLSX: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @return list<list<mixed>> */
    private function matrix(Worksheet $sheet): array
    {
        $highestRow = max(1, (int) $sheet->getHighestDataRow());
        $highestColumn = $sheet->getHighestDataColumn();
        return $sheet->rangeToArray("A1:{$highestColumn}{$highestRow}", null, true, true, false);
    }

    private function header(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace([' ', '-'], '_', $value);
        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }

    private function text(mixed $value): string
    {
        return trim(str_replace("\xC2\xA0", ' ', (string) ($value ?? '')));
    }

    /** @param list<mixed> $values */
    private function blank(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->text($value) !== '') {
                return false;
            }
        }
        return true;
    }
}
