<?php

declare(strict_types=1);

namespace App\Infrastructure\Geography;

use DomainException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int,array<string,string>> */
final readonly class NormalizedLocalityCsvRecords implements IteratorAggregate
{
    private const HEADER = [
        'pais_codigo', 'fuente', 'codigo_provincia', 'provincia', 'codigo_localidad',
        'localidad', 'contexto', 'categoria', 'latitud', 'longitud', 'version',
    ];

    public function __construct(private string $path)
    {
    }

    public function getIterator(): Traversable
    {
        $gzip = str_ends_with(strtolower($this->path), '.gz');
        $stream = $gzip ? gzopen($this->path, 'rb') : fopen($this->path, 'rb');
        if ($stream === false) {
            throw new DomainException('No se pudo leer el archivo de localidades.');
        }

        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($header)) {
                throw new DomainException('El archivo CSV está vacío.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if ($header !== self::HEADER) {
                throw new DomainException('El CSV no tiene las columnas del catálogo oficial normalizado.');
            }

            $line = 1;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $line++;
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (count($values) !== count(self::HEADER)) {
                    throw new DomainException('La fila ' . $line . ' no tiene las 11 columnas requeridas.');
                }
                $record = [];
                foreach (self::HEADER as $index => $field) {
                    $record[$field] = (string) ($values[$index] ?? '');
                }
                yield $record;
            }
        } finally {
            $gzip ? gzclose($stream) : fclose($stream);
        }
    }
}
