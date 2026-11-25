<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use DateTimeImmutable;

final readonly class CreateCustomerReceiptCommand
{
    /**
     * @param list<ReceiptInstrumentInput> $instruments
     * @param list<ReceiptImputationInput> $imputations
     */
    public function __construct(
        public int $customerId,
        public int $branchId,
        public string $series,
        public DateTimeImmutable $issueDate,
        public DateTimeImmutable $receptionDate,
        public ?string $notes,
        public array $instruments,
        public array $imputations = [],
        public bool $confirm = false,
    ) {
    }
}
