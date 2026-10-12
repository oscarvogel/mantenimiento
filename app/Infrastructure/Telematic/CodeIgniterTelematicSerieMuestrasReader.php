<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/** Prefiere la serie puntual Wialon a sus buckets cuando ambos existen en la ventana. */
final readonly class CodeIgniterTelematicSerieMuestrasReader implements SerieMuestrasReader
{
    public function __construct(
        private SerieMuestrasReader $puntos,
        private SerieMuestrasReader $buckets,
    ) {
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $puntos = $this->puntos->serie($empresaId, $equipoId, $desde, $hasta);
        $hayPuntosWialon = false;
        foreach ($puntos as $punto) {
            if (strtolower(trim($punto->proveedor())) === 'wialon') {
                $hayPuntosWialon = true;
                break;
            }
        }

        $buckets = $this->buckets->serie($empresaId, $equipoId, $desde, $hasta);
        if ($hayPuntosWialon) {
            $buckets = array_values(array_filter(
                $buckets,
                static fn (MuestraEquipo $bucket): bool => strtolower(trim($bucket->proveedor())) !== 'wialon',
            ));
        }

        $serie = array_merge($puntos, $buckets);

        usort(
            $serie,
            static fn (MuestraEquipo $a, MuestraEquipo $b): int => $a->observadaEn()->getTimestamp() <=> $b->observadaEn()->getTimestamp(),
        );

        return $serie;
    }
}
