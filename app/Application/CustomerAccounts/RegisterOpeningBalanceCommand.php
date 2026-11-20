<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use DateTimeImmutable;

final readonly class RegisterOpeningBalanceCommand
{
    public function __construct(
        public int $customerId,
        public string $side,
        public DateTimeImmutable $date,
        public float $amount,
        public string $currency,
        public ?float $exchangeRate,
        public ?string $reference,
        public ?string $notes = null,
    ) {
    }
}
