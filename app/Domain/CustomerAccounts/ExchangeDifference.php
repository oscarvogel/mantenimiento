<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

/**
 * Diferencia de cambio realizada al imputar un cobro contra una deuda (#588).
 *
 * Es la diferencia entre el valor del importe cancelado a la cotización del
 * pago y su valor histórico a la cotización congelada del cargo. Se registra
 * como un movimiento propio del libro: los movimientos originales no se
 * reescriben nunca.
 *
 * El signo sigue la convención del libro, que saldo es Debe menos Haber:
 * una diferencia favorable (el cobro valió más pesos que el valor histórico de
 * lo cancelado) se asienta en Debe, porque el crédito ya excedió el valor
 * histórico y el saldo del cliente tiene que bajar solo por el valor histórico
 * de lo cancelado.
 *
 * Cuando el saldo original de la moneda llega a cero, el ledger en pesos de esa
 * moneda llega a cero con la misma diferencia.
 */
final readonly class ExchangeDifference
{
    /** @param int $amountMinor Centavos de ARS con signo: positivo a favor. */
    public function __construct(private int $amountMinor)
    {
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    /** El cobro valió más pesos que el valor histórico de lo cancelado. */
    public function isFavorable(): bool
    {
        return $this->amountMinor > 0;
    }

    /** El cobro valió menos pesos que el valor histórico de lo cancelado. */
    public function isUnfavorable(): bool
    {
        return $this->amountMinor < 0;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function debitMinor(): int
    {
        return $this->amountMinor > 0 ? $this->amountMinor : 0;
    }

    public function creditMinor(): int
    {
        return $this->amountMinor < 0 ? -$this->amountMinor : 0;
    }
}
