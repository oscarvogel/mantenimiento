<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use DateTimeImmutable;

/**
 * Cobro de un cliente (#588): importe en su moneda, medio de pago y, si
 * corresponde, los documentos a los que se imputa.
 *
 * `deferred` deja el cobro anotado pero sin impacto en el libro: es el caso de
 * una transferencia pendiente de verificación o de un cheque que todavía no se
 * recibió. El impacto en la cuenta corriente ocurre al acreditarlo.
 */
final readonly class RegisterCustomerPaymentCommand
{
    /**
     * @param list<PaymentImputationInput> $imputations
     */
    public function __construct(
        public int $customerId,
        public DateTimeImmutable $date,
        public string $currency,
        public string $amount,
        public ?float $exchangeRate,
        public string $medium,
        public ?string $reference,
        public ?string $notes,
        public bool $deferred,
        public array $imputations,
    ) {
    }
}
