<?php

declare(strict_types=1);

namespace App\Application\Trips;

final readonly class DeleteOperationalTripCostCommand
{
    public function __construct(
        public int $tripId,
        public int $movementId,
        public string $type,
    ) {
    }
}
