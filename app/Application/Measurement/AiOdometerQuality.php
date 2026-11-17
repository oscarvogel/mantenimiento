<?php

declare(strict_types=1);

namespace App\Application\Measurement;

final readonly class AiOdometerQuality
{
    /**
     * @param list<array{equipmentCode: string, total: int, discrepancies: int, rate: float}> $byEquipment
     * @param list<array{month: string, total: int, discrepancies: int, rate: float}> $byMonth
     */
    public function __construct(
        public int $total,
        public int $discrepancies,
        public float $rate,
        public array $byEquipment,
        public array $byMonth,
    ) {
    }
}
