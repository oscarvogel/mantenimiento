<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use InvalidArgumentException;

/** Fila de transferencia completa para respaldar un bucket sin perder sus extremos. */
final readonly class BucketHistorico
{
    /**
     * @param array<string,int|float|string|null> $fila Columnas persistidas del bucket
     */
    public function __construct(private array $fila)
    {
        foreach (['empresa_id', 'integracion_id', 'equipo_id', 'unidad_externa', 'proveedor', 'bucket_inicio_utc', 'bucket_fin_utc'] as $columna) {
            if (! array_key_exists($columna, $fila)) {
                throw new InvalidArgumentException('El respaldo del bucket está incompleto: falta ' . $columna . '.');
            }
        }
    }

    /** @return array<string,int|float|string|null> */
    public function fila(): array
    {
        return $this->fila;
    }
}
