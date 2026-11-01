<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class UpdateTripExpenseCommand
{
    public function __construct(
        public int $tripId,
        public int $movementId,
        public DateTimeImmutable $date,
        public float $amount,
        public ?int $categoryId,
        public string $currency,
        public float $exchangeRate,
        public ?int $paymentMethodId,
        public ?int $legId,
        public string $paidBy,
        public ?string $receipt,
        public ?string $notes,
    ) {
    }
}
