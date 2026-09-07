<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

use App\Application\Employees\DriverAssignmentImportRow;
use DomainException;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class PhpSpreadsheetDriverAssignmentWorkbookReader
{
    /** @return list<DriverAssignmentImportRow> */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException('No se puede leer el archivo de choferes.');
        }

        $spreadsheet = IOFactory::load($path);
        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $headerRow = $this->findHeaderRow($worksheet->toArray(null, true, true, false));
            if ($headerRow === null) {
                continue;
            }

            $values = $worksheet->toArray(null, true, true, false);
            for ($index = $headerRow + 1, $count = count($values); $index < $count; $index++) {
                $row = $values[$index] ?? [];
                $brand = self::normalizeText((string) ($row[0] ?? ''));
                $plate = self::normalizeText((string) ($row[1] ?? ''));
                $driver = self::normalizeDriver((string) ($row[2] ?? ''));

                if ($brand === '' && $plate === '' && $driver === null) {
                    continue;
                }
                if ($plate === '') {
                    continue;
                }

                $rows[] = new DriverAssignmentImportRow(
                    $worksheet->getTitle(),
                    $index + 1,
                    $brand,
                    $plate,
                    self::normalizePlate($plate),
                    $driver,
                );
            }
        }

        if ($rows === []) {
            throw new DomainException('El archivo no contiene filas de móviles/choferes reconocibles.');
        }

        return $rows;
    }

    public static function normalizePlate(string $plate): string
    {
        $plate = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $plate);
        $plate = mb_strtoupper($plate);
        return preg_replace('/[^A-Z0-9]/u', '', $plate) ?? '';
    }

    public static function normalizeDriver(string $driver): ?string
    {
        $driver = self::normalizeText($driver);
        if ($driver === '' || in_array(mb_strtoupper($driver), ['-', '—', 'SIN CHOFER', 'S/CHOFER'], true)) {
            return null;
        }
        return $driver;
    }

    private static function normalizeText(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /** @param list<array<int,mixed>> $rows */
    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $brand = mb_strtoupper(self::normalizeText((string) ($row[0] ?? '')));
            $plate = mb_strtoupper(self::normalizeText((string) ($row[1] ?? '')));
            $driver = mb_strtoupper(self::normalizeText((string) ($row[2] ?? '')));

            if ($brand === 'MARCA'
                && str_contains($plate, 'PATENTE')
                && $driver === 'CHOFER') {
                return $index;
            }
        }

        return null;
    }
}
