<?php

declare(strict_types=1);

namespace App\Application\Trips;

final readonly class UpdateTripDriverPercentageCommand
{
    public function __construct(
        public int $tripId,
        public int $employeeId,
        public float $percentage,
    ) {
    }
}
