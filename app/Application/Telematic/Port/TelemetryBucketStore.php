<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\BucketTelemetria;
use DateTimeImmutable;

/** Persistencia append-only de buckets y avance atómico del cursor por unidad. */
interface TelemetryBucketStore
{
    public function cursor(int $integracionId, string $unidadExterna): ?DateTimeImmutable;

    /**
     * Guarda buckets idempotentes y avanza el cursor en una única transacción.
     *
     * @param list<BucketTelemetria> $buckets
     * @return int Cantidad de buckets insertados por primera vez.
     */
    public function guardarCerradosYAvanzarCursor(
        int $empresaId,
        int $integracionId,
        int $equipoId,
        string $unidadExterna,
        string $proveedor,
        array $buckets,
        DateTimeImmutable $cursorHasta,
        DateTimeImmutable $registradaEn,
    ): int;
}
