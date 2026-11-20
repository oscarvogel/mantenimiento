<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use DateTimeImmutable;

final readonly class FormalizeLegChargeCommand
{
    public function __construct(
        public int $legId,
        public string $modality,
        public DateTimeImmutable $documentDate,
        public ?string $documentNumber,
        public ?string $invoiceType,
        public float $amount,
        public string $currency,
        public ?float $exchangeRate,
        public ?string $notes = null,
    ) {
    }
}
