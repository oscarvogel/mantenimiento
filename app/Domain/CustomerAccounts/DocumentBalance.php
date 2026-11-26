<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Estado de cobranza de un documento de deuda (#588).
 *
 * Se deriva de la imputación de cobros en la moneda del documento: no es un
 * campo que alguien pueda desincronizar del libro. El estado de cobranza es
 * independiente del estado de la deuda: un documento formalizado puede estar
 * cancelado, y uno previsto todavía no formalizado no es exigible.
 */
final readonly class DocumentBalance
{
    /** El documento no tiene nada cobrado. */
    public const PENDIENTE = 'PENDIENTE';

    /** El documento tiene una parte cobrada y una parte pendiente. */
    public const PARCIAL = 'PARCIAL';

    /** El documento está cancelado en su moneda original. */
    public const CANCELADO = 'CANCELADO';

    public const LABELS = [
        self::PENDIENTE => 'Pendiente de cobro',
        self::PARCIAL => 'Cobro parcial',
        self::CANCELADO => 'Cancelado',
    ];

    public function __construct(
        private int $chargeId,
        private Currency $currency,
        private int $originalMinor,
        private int $paidMinor,
        private FrozenExchangeRate $rate,
    ) {
        if ($chargeId <= 0) {
            throw new DomainException('El documento necesita un identificador.');
        }
        if ($originalMinor < 0) {
            throw new DomainException('El importe del documento no puede ser negativo.');
        }
        if ($paidMinor < 0) {
            throw new DomainException('El importe cobrado no puede ser negativo.');
        }
        if ($paidMinor > $originalMinor) {
            throw new DomainException('No se puede cobrar más que el importe del documento.');
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

    public function originalMinor(): int
    {
        return $this->originalMinor;
    }

    public function paidMinor(): int
    {
        return $this->paidMinor;
    }

    /** Saldo pendiente en la moneda original del documento. */
    public function pendingMinor(): int
    {
        return $this->originalMinor - $this->paidMinor;
    }

    /** Valor histórico del saldo pendiente, a la cotización congelada. */
    public function historicalArsMinor(): int
    {
        return Money::multiplyByRate($this->pendingMinor(), $this->rate->rate());
    }

    public function state(): string
    {
        if ($this->pendingMinor() === 0) {
            return self::CANCELADO;
        }

        return $this->paidMinor() > 0 ? self::PARCIAL : self::PENDIENTE;
    }

    public function stateLabel(): string
    {
        return self::LABELS[$this->state()] ?? $this->state();
    }
}
