<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\SpreadsheetReader;
use App\Application\Importations\SpreadsheetData;
use DomainException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Traduce VENCIMIENTOS.xlsx (grupos Argentina/Brasil y hoja Choferes). */
final class TsaExpirationWorkbookReader implements SpreadsheetReader
{
    /** @var list<string> */
    private const HEADERS = ['equipo_codigo', 'tipo_vencimiento', 'fecha_vencimiento', 'fecha_emision', 'numero_documento', 'observaciones'];

    public function read(string $privatePath, int $maximumRows = 5000): SpreadsheetData
    {
        $book = $this->load($privatePath);
        try {
            $rows = [];
            foreach ($book->getWorksheetIterator() as $sheet) {
                $matrix = $this->matrix($sheet);
                $canonical = $this->canonicalHeader($matrix);
                if ($canonical !== null) {
                    $rows = array_merge($rows, $this->canonicalRows($matrix, $canonical, $maximumRows - count($rows)));
                    continue;
                }
                if (mb_strtolower(trim($sheet->getTitle())) === 'unidades') {
                    $rows = array_merge($rows, $this->unitRows($matrix, $maximumRows - count($rows)));
                } elseif (mb_strtolower(trim($sheet->getTitle())) === 'choferes') {
                    // La gestión de personal está fuera del alcance actual. Se
                    // conserva cada dato como error explícito para no perderlo.
                    $rows = array_merge($rows, $this->driverRows($matrix, $maximumRows - count($rows)));
                }
            }
            if ($rows === []) {
                throw new DomainException('El XLSX no contiene vencimientos reconocibles.');
            }
            return new SpreadsheetData(self::HEADERS, $rows);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** @return list<array<string,string|null>> */
    private function unitRows(array $matrix, int $remaining): array
    {
        $header = $this->findRow($matrix, static function (array $normalized): bool {
            return in_array('placa', $normalized, true) && in_array('vtv', $normalized, true);
        });
        if ($header === null) {
            return [];
        }
        $groups = [];
        foreach ($header['values'] as $column => $name) {
            if ($name !== 'placa') {
                continue;
            }
            $groups[] = [
                'plate' => $column,
                'branch' => $column < 6 ? 'TSAARG' : 'TSABR',
                'fields' => $this->fieldColumns($header['values'], $column),
            ];
        }

        $rows = [];
        foreach (array_slice($matrix, $header['row'] + 1) as $sourceRow => $values) {
            foreach ($groups as $group) {
                $plate = $this->plate($values[$group['plate']] ?? null);
                if ($plate === null) {
                    continue;
                }
                foreach ($group['fields'] as $field => $column) {
                    $date = $this->date($values[$column] ?? null);
                    if ($date === null) {
                        continue;
                    }
                    if (count($rows) >= $remaining) {
                        throw new DomainException('El archivo supera el limite de filas de datos.');
                    }
                    $rows[] = [
                        'equipo_codigo' => $plate,
                        'tipo_vencimiento' => $field,
                        'fecha_vencimiento' => $date,
                        'fecha_emision' => null,
                        'numero_documento' => null,
                        'observaciones' => 'Fuente: hoja unidades de VENCIMIENTOS.xlsx; sucursal sugerida ' . $group['branch'] . '; fila ' . ($header['row'] + $sourceRow + 2) . '.',
                    ];
                }
            }
        }
        return $rows;
    }

    /** @return list<array<string,string|null>> */
    private function driverRows(array $matrix, int $remaining): array
    {
        $header = $this->findRow($matrix, static function (array $normalized): bool {
            return in_array('chofer', $normalized, true);
        });
        if ($header === null) {
            return [];
        }
        $driverColumns = [];
        foreach ($header['values'] as $column => $name) {
            if ($name !== 'chofer') {
                continue;
            }
            $end = array_search('chofer', array_slice($header['values'], $column + 1), true);
            $end = $end === false ? count($header['values']) : $column + 1 + (int) $end;
            for ($index = $column + 1; $index < $end; $index++) {
                if ($header['values'][$index] !== '') {
                    $driverColumns[$index] = $header['values'][$index];
                }
            }
        }
        $rows = [];
        foreach (array_slice($matrix, $header['row'] + 1) as $sourceRow => $values) {
            foreach ($driverColumns as $column => $label) {
                $driver = $this->text($values[$this->driverNameColumn($header['values'], $column)] ?? null);
                $date = $this->date($values[$column] ?? null);
                if ($driver === '' || $date === null) {
                    continue;
                }
                if (count($rows) >= $remaining) {
                    throw new DomainException('El archivo supera el limite de filas de datos.');
                }
                $rows[] = [
                    'equipo_codigo' => null,
                    'tipo_vencimiento' => 'LICENCIA_CHOFER',
                    'fecha_vencimiento' => $date,
                    'fecha_emision' => null,
                    'numero_documento' => null,
                    'observaciones' => 'No importado: vencimiento de chofer ' . $driver . ' (' . $label . '), hoja Choferes, fila ' . ($header['row'] + $sourceRow + 2) . '. La gestión de personal todavía no está habilitada.',
                ];
            }
        }
        return $rows;
    }

    /** @param list<string> $headers @return array<string,int> */
    private function fieldColumns(array $headers, int $plateColumn): array
    {
        $fields = [];
        $known = ['vtv' => 'VTV', 'senasa' => 'SENASA', 'poliza' => 'POLIZA', 'crvl' => 'CRVL'];
        foreach (array_slice($headers, $plateColumn + 1, 5, true) as $column => $name) {
            if (isset($known[$name])) {
                $fields[$known[$name]] = $column;
            }
        }
        return $fields;
    }

    /** @param list<string> $headers */
    private function driverNameColumn(array $headers, int $dateColumn): int
    {
        for ($column = $dateColumn - 1; $column >= 0; $column--) {
            if ($headers[$column] === 'chofer') {
                return $column;
            }
        }
        return max(0, $dateColumn - 1);
    }

    /** @return array{row:int,values:list<string>}|null */
    private function findRow(array $matrix, callable $predicate): ?array
    {
        foreach ($matrix as $row => $values) {
            $normalized = array_map(fn (mixed $value): string => $this->header((string) $value), $values);
            if ($predicate($normalized)) {
                return ['row' => $row, 'values' => $normalized];
            }
        }
        return null;
    }

    /** @return array{row:int,headers:list<string>}|null */
    private function canonicalHeader(array $matrix): ?array
    {
        foreach ($matrix as $row => $values) {
            $headers = array_values(array_map(fn (mixed $value): string => $this->header((string) $value), $values));
            if (in_array('equipo_codigo', $headers, true) && in_array('tipo_vencimiento', $headers, true)) {
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
            if ($row['fecha_vencimiento'] !== null) {
                $row['fecha_vencimiento'] = $this->date($row['fecha_vencimiento']);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function date(mixed $value): ?string
    {
        if (is_numeric($value) && (float) $value > 1000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $value = $this->text($value);
        if ($value === '' || preg_match('/^[-—]+$/u', $value) === 1) {
            return null;
        }
        foreach (['!Y-m-d', '!d/m/Y', '!d/n/Y', '!j/m/Y', '!j/n/Y', '!m/d/Y', '!n/j/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false && $parsed->format(ltrim($format, '!')) === $value) {
                return $parsed->format('Y-m-d');
            }
        }
        return null;
    }

    private function load(string $path): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx' || ! is_file($path) || ! str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04")) {
            throw new DomainException('El archivo de vencimientos debe ser un XLSX valido.');
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

    private function plate(mixed $value): ?string
    {
        $value = $this->text($value);
        return $value === '' ? null : mb_strtoupper((string) preg_replace('/\s+/u', '', $value));
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
