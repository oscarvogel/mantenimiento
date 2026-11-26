<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use DomainException;

/**
 * Imputación pedida por la pantalla: contra qué documento y cuánto se cobra,
 * en la moneda del documento.
 */
final readonly class PaymentImputationInput
{
    public function __construct(
        public int $chargeId,
        public string $amount,
    ) {
        if ($chargeId <= 0) {
            throw new DomainException('Elegí el documento al que se imputa el pago.');
        }
    }
}
