<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class UpdateTripCashMovementCommand
{
    public function __construct(
        public int $tripId,
        public int $movementId,
        public string $type,
        public DateTimeImmutable $date,
        public float $amount,
        public string $currency,
        public float $exchangeRate,
        public ?string $notes,
    ) {
    }
}
