<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Imputación de un recibo a un cargo concreto, expresada en ARS.
 *
 * La imputación no crea un movimiento en el libro: el crédito ya nació al
 * acreditarse el medio de pago. Solo asigna ese crédito disponible al documento
 * que cancela, total o parcialmente.
 */
final readonly class ReceiptImputation
{
    public function __construct(
        private int $chargeId,
        private int $amountMinor,
    ) {
        if ($chargeId <= 0) {
            throw new DomainException('Elegí el documento al que se imputa el pago.');
        }
        if ($amountMinor <= 0) {
            throw new DomainException('El importe imputado debe ser mayor que cero.');
        }
    }

    public static function fromDecimal(int $chargeId, string|int|float $amount, int $scale = Money::SCALE): self
    {
        return new self($chargeId, Money::toMinor($amount, $scale));
    }

    public function chargeId(): int
    {
        return $this->chargeId;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function amount(): float
    {
        return Money::toDecimal($this->amountMinor);
    }

    /** @return array{cargo_id:int,importe_ars:float} */
    public function snapshot(): array
    {
        return ['cargo_id' => $this->chargeId, 'importe_ars' => $this->amount()];
    }
}
