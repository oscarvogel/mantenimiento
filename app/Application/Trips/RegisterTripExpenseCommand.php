<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class RegisterTripExpenseCommand
{
    public function __construct(
        public int $tripId,
        public DateTimeImmutable $date,
        public float $amount,
        public ?int $categoryId = null,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public ?int $paymentMethodId = null,
        public bool $paidFromCash = false,
        public ?string $receipt = null,
        public ?string $notes = null,
        public ?int $legId = null,
    ) {
    }
}
