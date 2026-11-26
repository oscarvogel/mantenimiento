<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Imputación de un cobro contra un documento de deuda (#588).
 *
 * La imputación se expresa **en la moneda de la deuda**, no en pesos: un
 * documento en USD se cancela con USD. Cada imputación guarda el importe
 * imputado en la moneda de la deuda, el valor histórico que le corresponde del
 * documento y la cotización del cobro; de ahí sale la diferencia de cambio
 * realizada, que queda registrada como un movimiento propio del libro.
 *
 * El valor histórico no se recalcula multiplicando por la cotización del
 * documento: se reparte del valor histórico que el documento ya tenía, de
 * manera que las partes suman exactamente el documento. Así, cuando la deuda
 * en su moneda original llega a cero, el ledger en pesos de esa moneda llega
 * a cero: no quedan saldos residuales por redondeo.
 *
 * No se admiten imputaciones cruzadas: la moneda del cobro y la de la deuda
 * tienen que ser la misma. Un anticipo sin imputar es un saldo a favor, no una
 * compensación automática.
 */
final readonly class PaymentImputation
{
    public function __construct(
        private int $chargeId,
        private Currency $currency,
        private int $amountMinor,
        private int $historicalArsMinor,
        private FrozenExchangeRate $chargeRate,
        private FrozenExchangeRate $paymentRate,
    ) {
        if ($chargeId <= 0) {
            throw new DomainException('Elegí el documento al que se imputa el pago.');
        }
        if ($amountMinor <= 0) {
            throw new DomainException('El importe imputado debe ser mayor que cero.');
        }
        if ($historicalArsMinor < 0) {
            throw new DomainException('El valor histórico del documento no puede ser negativo.');
        }
        if ($paymentRate->currency() !== $currency->code()) {
            throw new DomainException('Un pago solo puede imputarse a una deuda de la misma moneda.');
        }
    }

    public function chargeId(): int
    {
        return $this->chargeId;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /** Importe imputado en la moneda de la deuda, en centavos. */
    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    /** Parte del valor histórico del documento que este cobro cancela. */
    public function historicalArsMinor(): int
    {
        return $this->historicalArsMinor;
    }

    public function paymentRate(): FrozenExchangeRate
    {
        return $this->paymentRate;
    }

    /** Cotización congelada del documento que se cancela. */
    public function chargeRate(): FrozenExchangeRate
    {
        return $this->chargeRate;
    }

    /** Valor del importe imputado a la cotización del cobro. */
    public function paymentArsMinor(): int
    {
        return Money::multiplyByRate($this->amountMinor, $this->paymentRate->rate());
    }

    /**
     * Diferencia de cambio realizada: el valor del cobro menos el valor
     * histórico de lo cancelado. Positiva a favor.
     */
    public function difference(): ExchangeDifference
    {
        return new ExchangeDifference($this->paymentArsMinor() - $this->historicalArsMinor);
    }
}
