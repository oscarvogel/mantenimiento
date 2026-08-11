<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\PreventiveLibraryWorkbookReader;
use DomainException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ZipArchive;

final class PhpSpreadsheetPreventiveLibraryReader implements PreventiveLibraryWorkbookReader
{
    /** @var array<string,list<string>> */
    private const REQUIRED_SHEETS = [
        'SERVICIOS' => ['codigo_servicio', 'nombre', 'descripcion', 'categoria', 'activo'],
        'TAREAS_SERVICIO' => ['codigo_servicio', 'orden', 'codigo_tarea', 'tarea', 'descripcion', 'obligatoria', 'activo'],
        'REPUESTOS_SERVICIO' => [
            'codigo_servicio', 'codigo_item', 'descripcion_item', 'tipo_item', 'unidad',
            'cantidad_referencia', 'cantidad_variable', 'codigo_repuesto_catalogo',
            'obligatorio', 'observaciones', 'activo',
        ],
        'PLANTILLAS' => [
            'codigo_plantilla', 'nombre', 'ambito', 'codigo_empresa', 'tipo_equipo',
            'marca', 'modelo', 'descripcion', 'activo',
        ],
        'ITEMS_PLANTILLA' => [
            'codigo_plantilla', 'codigo_servicio', 'intervalo_km', 'intervalo_horas',
            'intervalo_dias', 'anticipacion_km', 'anticipacion_horas',
            'anticipacion_dias', 'prioridad', 'activo', 'observaciones',
        ],
    ];

    public function read(string $privatePath, int $maximumRows = 5000): array
    {
        if (strtolower(pathinfo($privatePath, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new DomainException('La biblioteca preventiva se importa unicamente desde un archivo XLSX.');
        }
        if (! is_file($privatePath) || ! str_starts_with((string) file_get_contents($privatePath, false, null, 0, 4), "PK\x03\x04")) {
            throw new DomainException('El archivo de biblioteca preventiva no es un XLSX valido.');
        }
        if (! class_exists(IOFactory::class)) {
            throw new DomainException('No se puede leer XLSX: falta instalar phpoffice/phpspreadsheet.');
        }
        if (! class_exists(\ZipArchive::class)) {
            throw new DomainException('No se puede leer XLSX: falta habilitar la extension PHP zip.');
        }

        $sanitizedPath = null;

        try {
            $sanitizedPath = $this->withoutTableMetadata($privatePath);
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setLoadSheetsOnly(array_keys(self::REQUIRED_SHEETS));
            $spreadsheet = $reader->load($sanitizedPath);
            return $this->rows($spreadsheet, $maximumRows);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new DomainException('No se pudo leer la biblioteca preventiva: ' . $exception->getMessage(), 0, $exception);
        } finally {
            if (isset($spreadsheet) && $spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }
            if ($sanitizedPath !== null && is_file($sanitizedPath)) {
                @unlink($sanitizedPath);
            }
        }
    }

    /** @return list<array{sheet:string,row:int,data:array<string,string|null>}> */
    private function rows(Spreadsheet $spreadsheet, int $maximumRows): array
    {
        $sheetsByName = [];
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $sheetsByName[mb_strtoupper(trim($sheet->getTitle()))] = $sheet;
        }

        $missingSheets = array_values(array_diff(array_keys(self::REQUIRED_SHEETS), array_keys($sheetsByName)));
        if ($missingSheets !== []) {
            throw new DomainException('Faltan hojas obligatorias: ' . implode(', ', $missingSheets) . '.');
        }

        $result = [];
        foreach (self::REQUIRED_SHEETS as $sheetName => $requiredHeaders) {
            $sheet = $sheetsByName[$sheetName];
            $highestRow = max(1, (int) $sheet->getHighestDataRow());
            $highestColumn = $sheet->getHighestDataColumn();
            $matrix = $sheet->rangeToArray("A1:{$highestColumn}{$highestRow}", null, true, true, false);
            if ($matrix === []) {
                throw new DomainException("La hoja {$sheetName} esta vacia.");
            }

            $rawHeaders = $this->withoutTrailingEmptyCells(array_shift($matrix));
            $headers = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $rawHeaders);
            if ($headers === [] || in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                throw new DomainException("La hoja {$sheetName} contiene encabezados vacios o repetidos.");
            }
            $missing = array_values(array_diff($requiredHeaders, $headers));
            if ($missing !== []) {
                throw new DomainException("En {$sheetName} faltan encabezados: " . implode(', ', $missing) . '.');
            }

            foreach ($matrix as $index => $values) {
                if ($this->blank($values)) {
                    continue;
                }
                if (count($result) >= $maximumRows) {
                    throw new DomainException("La biblioteca supera el limite de {$maximumRows} filas de datos.");
                }
                $data = [];
                foreach ($headers as $column => $header) {
                    $value = $values[$column] ?? null;
                    $data[$header] = $value === null ? null : trim((string) $value);
                }
                $result[] = ['sheet' => $sheetName, 'row' => $index + 2, 'data' => $data];
            }
        }

        if ($result === []) {
            throw new DomainException('La biblioteca preventiva no contiene filas de datos.');
        }

        return $result;
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

    /** @param list<mixed> $values @return list<mixed> */
    private function withoutTrailingEmptyCells(array $values): array
    {
        while ($values !== [] && trim((string) end($values)) === '') {
            array_pop($values);
        }

        return $values;
    }

    private function withoutTableMetadata(string $privatePath): string
    {
        $source = new ZipArchive();
        if ($source->open($privatePath) !== true) {
            throw new DomainException('No se pudo abrir el XLSX de biblioteca preventiva.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mantenimiento_xlsx_');
        if ($temporary === false) {
            $source->close();
            throw new DomainException('No se pudo crear el archivo temporal de lectura XLSX.');
        }

        $target = new ZipArchive();
        if ($target->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();
            @unlink($temporary);
            throw new DomainException('No se pudo preparar el archivo temporal de lectura XLSX.');
        }

        try {
            for ($index = 0; $index < $source->numFiles; $index++) {
                $name = (string) $source->getNameIndex($index);
                if ($name === '' || $this->isTableMetadataEntry($name)) {
                    continue;
                }

                $contents = $source->getFromIndex($index);
                if ($contents === false) {
                    throw new DomainException('No se pudo copiar una parte interna del XLSX.');
                }

                if ($this->isWorksheetEntry($name)) {
                    $contents = $this->removeTableParts($contents);
                } elseif ($this->isWorksheetRelationshipEntry($name)) {
                    $contents = $this->removeTableRelationships($contents);
                }

                $target->addFromString($name, $contents);
            }
        } finally {
            $target->close();
            $source->close();
        }

        return $temporary;
    }

    private function isTableMetadataEntry(string $name): bool
    {
        return str_starts_with($name, 'xl/tables/');
    }

    private function isWorksheetEntry(string $name): bool
    {
        return preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1;
    }

    private function isWorksheetRelationshipEntry(string $name): bool
    {
        return preg_match('#^xl/worksheets/_rels/sheet\d+\.xml\.rels$#', $name) === 1;
    }

    private function removeTableParts(string $xml): string
    {
        return preg_replace('#<tableParts\b[^>]*/>|<tableParts\b[^>]*>.*?</tableParts>#s', '', $xml) ?? $xml;
    }

    private function removeTableRelationships(string $xml): string
    {
        return preg_replace(
            '#<Relationship\b(?=[^>]*Type="[^"]*/table")[^>]*/>|<Relationship\b(?=[^>]*Type="[^"]*/table")[^>]*>.*?</Relationship>#s',
            '',
            $xml,
        ) ?? $xml;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? '';
        $header = mb_strtolower($header);
        $header = str_replace([' ', '-'], '_', $header);
        return preg_replace('/[^a-z0-9_]/', '', $header) ?? '';
    }
}
