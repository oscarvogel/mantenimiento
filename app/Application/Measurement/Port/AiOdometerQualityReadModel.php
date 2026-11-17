<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use App\Application\Measurement\AiOdometerQuality;
use DateTimeImmutable;

interface AiOdometerQualityReadModel
{
    /** @param list<int>|null $branchIds */
    public function summary(int $companyId, ?array $branchIds, DateTimeImmutable $now): AiOdometerQuality;
}
