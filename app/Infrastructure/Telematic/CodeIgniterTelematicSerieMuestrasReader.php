<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/** Combina puntos de otros proveedores con buckets Wialon en una serie ordenada. */
final readonly class CodeIgniterTelematicSerieMuestrasReader implements SerieMuestrasReader
{
    public function __construct(
        private SerieMuestrasReader $puntos,
        private SerieMuestrasReader $buckets,
    ) {
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $serie = array_merge(
            $this->puntos->serie($empresaId, $equipoId, $desde, $hasta),
            $this->buckets->serie($empresaId, $equipoId, $desde, $hasta),
        );

        usort(
            $serie,
            static fn (MuestraEquipo $a, MuestraEquipo $b): int => $a->observadaEn()->getTimestamp() <=> $b->observadaEn()->getTimestamp(),
        );

        return $serie;
    }
}
