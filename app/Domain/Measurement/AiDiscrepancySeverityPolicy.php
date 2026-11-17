<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

final class AiDiscrepancySeverityPolicy
{
    public const EQUIPMENT_THRESHOLD = 3;
    public const COMPANY_THRESHOLD = 5;

    public function isCritical(int $equipmentCount, int $companyCount): bool
    {
        return $equipmentCount >= self::EQUIPMENT_THRESHOLD
            || $companyCount >= self::COMPANY_THRESHOLD;
    }
}
