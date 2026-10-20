<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class RegisterTripFuelCommand
{
    public function __construct(
        public int $tripId,
        public DateTimeImmutable $date,
        public float $liters,
        public float $amount,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public ?int $odometer = null,
        public ?string $station = null,
        public ?string $receipt = null,
        public ?string $notes = null,
    ) {
    }
}
