<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Application\Telematic\BucketHistorico;
use DateTimeImmutable;

/** Lectura y purga de buckets históricos bajo el scope de una empresa. */
interface HistoricoBuckets
{
    /** @return list<BucketHistorico> Buckets cuyo último extremo precede al corte. */
    public function anterioresA(int $empresaId, DateTimeImmutable $corte): array;

    public function borrarAnterioresA(int $empresaId, DateTimeImmutable $corte): int;
}
