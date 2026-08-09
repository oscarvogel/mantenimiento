<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\SpreadsheetReader;
use App\Application\Importations\SpreadsheetData;
use DomainException;
use SplFileObject;

final class NativeCsvSpreadsheetReader implements SpreadsheetReader
{
    public function read(string $privatePath, int $maximumRows = 5000): SpreadsheetData
    {
        if (! is_file($privatePath) || ! is_readable($privatePath)) {
            throw new DomainException('El archivo CSV privado no esta disponible.');
        }
        $handle = fopen($privatePath, 'rb');
        if ($handle === false) {
            throw new DomainException('No se pudo abrir el archivo CSV.');
        }
        $sample = (string) fgets($handle);
        fclose($handle);
        if (str_starts_with($sample, "PK\x03\x04")) {
            throw new DomainException('El contenido es XLSX y no CSV.');
        }
        $delimiter = $this->delimiter($sample);
        $file = new SplFileObject($privatePath, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter);

        $headers = null;
        $rows = [];
        foreach ($file as $values) {
            if (! is_array($values) || $values === [null]) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $values);
                continue;
            }
            if ($this->blank($values)) {
                continue;
            }
            if (count($rows) >= $maximumRows) {
                throw new DomainException("El archivo supera el limite de {$maximumRows} filas.");
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $value = $values[$index] ?? null;
                $row[$header] = $value === null ? null : trim((string) $value);
            }
            $rows[] = $row;
        }
        if ($headers === null || $headers === [] || in_array('', $headers, true)) {
            throw new DomainException('El archivo CSV no contiene encabezados validos.');
        }
        return new SpreadsheetData(array_values($headers), $rows);
    }

    private function delimiter(string $sample): string
    {
        $counts = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        if (($counts[$delimiter] ?? 0) === 0) {
            return ',';
        }
        return $delimiter;
    }

    /** @param array<int, mixed> $values */
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
