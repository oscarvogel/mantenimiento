<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class UpdateTripOtherEarningCommand
{
    public function __construct(
        public int $tripId,
        public int $movementId,
        public int $employeeId,
        public DateTimeImmutable $date,
        public float $amount,
        public string $currency,
        public float $exchangeRate,
        public ?int $conceptId,
        public float $quantity,
        public string $concept,
        public ?string $notes,
    ) {
    }
}
