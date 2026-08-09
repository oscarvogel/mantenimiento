<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\SpreadsheetReader;
use App\Application\Importations\SpreadsheetData;
use DomainException;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class PhpSpreadsheetReader implements SpreadsheetReader
{
    public function __construct(private readonly NativeCsvSpreadsheetReader $csv)
    {
    }

    public function read(string $privatePath, int $maximumRows = 5000): SpreadsheetData
    {
        $extension = strtolower(pathinfo($privatePath, PATHINFO_EXTENSION));
        $signature = is_file($privatePath) ? (string) file_get_contents($privatePath, false, null, 0, 4) : '';
        $isZip = str_starts_with($signature, "PK\x03\x04");

        if ($extension === 'csv' && ! $isZip) {
            return $this->csv->read($privatePath, $maximumRows);
        }
        if ($extension !== 'xlsx' || ! $isZip) {
            throw new DomainException('El archivo debe ser CSV real o XLSX valido.');
        }
        if (! class_exists(IOFactory::class)) {
            throw new DomainException('No se puede leer XLSX: falta instalar phpoffice/phpspreadsheet.');
        }
        $missingExtensions = [];
        if (! class_exists(\ZipArchive::class)) {
            $missingExtensions[] = 'zip';
        }
        if (! extension_loaded('gd')) {
            $missingExtensions[] = 'gd';
        }
        if ($missingExtensions !== []) {
            throw new DomainException('No se puede leer XLSX: falta habilitar extension PHP ' . implode(' y ', $missingExtensions) . '.');
        }

        try {
            $spreadsheet = IOFactory::load($privatePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            if ($highestRow - 1 > $maximumRows) {
                throw new DomainException("El archivo supera el limite de {$maximumRows} filas.");
            }
            $matrix = $sheet->rangeToArray("A1:{$highestColumn}{$highestRow}", null, true, true, false);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new DomainException('No se pudo leer el XLSX: ' . $exception->getMessage(), 0, $exception);
        }

        if ($matrix === []) {
            throw new DomainException('El XLSX esta vacio.');
        }
        $rawHeaders = array_shift($matrix);
        $headers = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $rawHeaders);
        if ($headers === [] || in_array('', $headers, true)) {
            throw new DomainException('El XLSX no contiene encabezados validos.');
        }
        $rows = [];
        foreach ($matrix as $values) {
            if ($this->blank($values)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $value = $values[$index] ?? null;
                $row[$header] = $value === null ? null : trim((string) $value);
            }
            $rows[] = $row;
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        return new SpreadsheetData(array_values($headers), $rows);
    }

    /** @param array<int,mixed> $values */
    private function blank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? '';
        $header = mb_strtolower($header);
        $header = str_replace([' ', '-'], '_', $header);
        return preg_replace('/[^a-z0-9_]/', '', $header) ?? '';
    }
}
