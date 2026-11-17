<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

interface AiDiscrepancyMetrics
{
    public function equipmentCount(int $companyId, int $equipmentId, int $days): int;

    public function companyCount(int $companyId, int $days): int;
}
