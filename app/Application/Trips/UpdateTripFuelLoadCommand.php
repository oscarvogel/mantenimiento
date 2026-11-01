<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class UpdateTripFuelLoadCommand
{
    public function __construct(
        public int $tripId,
        public int $movementId,
        public DateTimeImmutable $date,
        public float $liters,
        public float $amount,
        public string $currency,
        public float $exchangeRate,
        public ?int $odometer,
        public ?string $station,
        public ?string $receipt,
        public ?string $notes,
        public ?int $legId,
    ) {
    }
}
