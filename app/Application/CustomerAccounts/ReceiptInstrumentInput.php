<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use DateTimeImmutable;

/**
 * Línea de medio de pago tal como llega del formulario. La conversión a
 * `PaymentInstrument` y la resolución de la cotización ocurren en el caso de
 * uso, dentro de la transacción.
 */
final readonly class ReceiptInstrumentInput
{
    public function __construct(
        public string $kind,
        public string $currency,
        public string $amount,
        public ?string $rate = null,
        public ?string $bank = null,
        public ?string $account = null,
        public ?string $holder = null,
        public ?DateTimeImmutable $transferDate = null,
        public ?string $reference = null,
        public string $verification = 'PENDIENTE',
        public ?string $chequeNumber = null,
        public ?string $drawer = null,
        public ?string $drawerTaxId = null,
        public ?DateTimeImmutable $chequeIssueDate = null,
        public ?DateTimeImmutable $chequeDueDate = null,
        public ?DateTimeImmutable $chequeReceivedDate = null,
        public ?string $notes = null,
    ) {
    }
}
