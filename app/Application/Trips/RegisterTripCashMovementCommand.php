<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class RegisterTripCashMovementCommand
{
    public function __construct(
        public int $tripId,
        public string $type,
        public DateTimeImmutable $date,
        public float $amount,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public ?string $notes = null,
    ) {
    }
}
