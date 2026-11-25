<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

final readonly class ImputeCustomerPaymentCommand
{
    /** @param list<ReceiptImputationInput> $imputations */
    public function __construct(
        public int $receiptId,
        public array $imputations,
    ) {
    }
}
