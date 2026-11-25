<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

/** Imputación solicitada desde el formulario o desde la acción de anticipos. */
final readonly class ReceiptImputationInput
{
    public function __construct(
        public int $chargeId,
        public string $amount,
    ) {
    }
}
