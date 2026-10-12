<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/** Prefiere puntos Wialon frente a extremos del mismo bucket de cinco minutos. */
final readonly class CodeIgniterTelematicSerieMuestrasReader implements SerieMuestrasReader
{
    private const SEGUNDOS_BUCKET = 300;

    public function __construct(
        private SerieMuestrasReader $puntos,
        private SerieMuestrasReader $buckets,
    ) {
    }

    public function serie(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $puntos = $this->puntos->serie($empresaId, $equipoId, $desde, $hasta);
        $bucketsConPuntosWialon = [];
        foreach ($puntos as $punto) {
            if (strtolower(trim($punto->proveedor())) === 'wialon') {
                $bucketsConPuntosWialon[intdiv($punto->observadaEn()->getTimestamp(), self::SEGUNDOS_BUCKET)] = true;
            }
        }

        $buckets = $this->buckets->serie($empresaId, $equipoId, $desde, $hasta);
        if ($bucketsConPuntosWialon !== []) {
            $buckets = array_values(array_filter(
                $buckets,
                static fn (MuestraEquipo $bucket): bool => strtolower(trim($bucket->proveedor())) !== 'wialon'
                    || ! isset($bucketsConPuntosWialon[intdiv($bucket->observadaEn()->getTimestamp(), self::SEGUNDOS_BUCKET)]),
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
