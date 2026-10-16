<?php

declare(strict_types=1);

namespace App\Infrastructure\Customers;

use App\Application\Customers\Port\CustomerImportReader;
use App\Domain\Customers\CustomerImportRow;
use DomainException;

final class CustomerCsvReader implements CustomerImportReader
{
    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw new DomainException('El CSV debe existir y pesar como máximo 5 MB.');
        }
        $content = file_get_contents($path);
        if ($content === false || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new DomainException('El archivo debe ser un CSV de texto UTF-8.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        try {
            $first = strtok($content, "\r\n") ?: '';
            $separator = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $header = fgetcsv($stream, null, $separator, '"', '');
            if ($header === false || count($header) !== count(CustomerImportRow::FIELDS)
                || count(array_unique($header)) !== count($header)
                || array_diff(CustomerImportRow::FIELDS, $header) !== []) {
                throw new DomainException('Encabezados incorrectos. Descargá la plantilla de clientes.');
            }
            $rows = [];
            $keys = [];
            $names = [];
            $documents = [];
            $number = 1;
            while (($values = fgetcsv($stream, null, $separator, '"', '')) !== false) {
                $number++;
                if ($values === [null]) {
                    continue;
                }
                if (count($rows) >= 10000) {
                    throw new DomainException('El límite es de 10.000 clientes por archivo.');
                }
                $row = count($values) !== count($header)
                    ? ['data' => [], 'errors' => ['Cantidad de columnas incorrecta.'], 'warnings' => []]
                    : CustomerImportRow::validate(array_combine($header, $values));
                $row['number'] = $number;
                $i = count($rows);
                if ($row['data'] !== []) {
                    $data = $row['data'];
                    $keys[json_encode([$data['origen'], $data['codigo_origen']])][] = $i;
                    $names[mb_strtoupper($data['nombre'])][] = $i;
                    $document = preg_replace('/\D/', '', $data['identificacion_fiscal']);
                    if (trim($document, '0') !== '') {
                        $documents[$document][] = $i;
                    }
                }
                $rows[] = $row;
            }
            if ($rows === []) {
                throw new DomainException('El archivo no contiene clientes.');
            }
            foreach ($keys as $indexes) {
                if (count($indexes) > 1) {
                    foreach ($indexes as $i) {
                        $rows[$i]['errors'][] = 'Código de origen repetido dentro del archivo.';
                    }
                }
            }
            foreach ([$names, $documents] as $groups) {
                foreach ($groups as $indexes) {
                    if (count($indexes) > 1) {
                        foreach ($indexes as $i) {
                            $rows[$i]['warnings'][] = 'Nombre o identificación repetidos: revisar, no se fusionarán.';
                        }
                    }
                }
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
