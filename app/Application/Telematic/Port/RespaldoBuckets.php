<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Application\Telematic\BucketHistorico;

/** Respaldo verificable del registro completo de cada bucket. */
interface RespaldoBuckets
{
    /** @param list<BucketHistorico> $buckets */
    public function escribir(string $archivo, array $buckets): int;

    public function verificar(string $archivo, int $esperados): bool;
}
