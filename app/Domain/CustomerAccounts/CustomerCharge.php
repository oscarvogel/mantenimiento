<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Cargo en la cuenta corriente del cliente (#530).
 *
 * Es la obligación comercial que nace una sola vez, en ARS, cuando un tramo se
 * formaliza por memo autorizado, factura emitida o cierre comercial aprobado, o
 * cuando se registra el saldo de apertura de un cliente. Conserva el importe y
 * la moneda originales y la cotización congelada en el evento; el Debe ARS es
 * definitivo y no se recalcula. Una corrección se hace anulándolo con un
 * contraasiento, nunca editándolo.
 */
final readonly class CustomerCharge
{
    public const MEMO_AUTORIZADO = 'MEMO_AUTORIZADO';
    public const FACTURA_EMITIDA = 'FACTURA_EMITIDA';
    public const CIERRE_COMERCIAL_APROBADO = 'CIERRE_COMERCIAL_APROBADO';
    public const SALDO_INICIAL = 'SALDO_INICIAL';

    /** Un cargo de tramo siempre va al Debe; el saldo inicial puede ser a favor del cliente. */
    public const DEBE = 'DEBE';
    public const HABER = 'HABER';

    /** Modalidades con las que se formaliza un tramo. */
    public const LEG_MODALITIES = [self::MEMO_AUTORIZADO, self::FACTURA_EMITIDA, self::CIERRE_COMERCIAL_APROBADO];

    public const LABELS = [
        self::MEMO_AUTORIZADO => 'Memo autorizado',
        self::FACTURA_EMITIDA => 'Factura emitida',
        self::CIERRE_COMERCIAL_APROBADO => 'Cierre comercial aprobado',
        self::SALDO_INICIAL => 'Saldo inicial',
    ];

    private float $amountArs;

    private function __construct(
        private int $customerId,
        private ?BillableLeg $leg,
        private ChargeDocument $document,
        private float $originalAmount,
        private FrozenExchangeRate $rate,
        private ?string $notes,
        private string $side = self::DEBE,
    ) {
        if (! in_array($side, [self::DEBE, self::HABER], true)) {
            throw new DomainException('Indicá si el saldo es a cobrar o a favor del cliente.');
        }
        if ($customerId <= 0) {
            throw new DomainException('El cargo necesita un cliente.');
        }
        if ($originalAmount <= 0) {
            throw new DomainException('El importe a formalizar debe ser mayor que cero.');
        }
        if ($notes !== null && mb_strlen($notes) > 500) {
            throw new DomainException('Las observaciones admiten hasta 500 caracteres.');
        }

        $this->amountArs = $rate->toArs($originalAmount);
        if ($this->amountArs <= 0) {
            throw new DomainException('El importe en pesos debe ser mayor que cero.');
        }
    }

    /**
     * Formaliza un tramo cobrable. El tramo debe tener cliente, pertenecer a un
     * viaje finalizado y no tener otro cargo vigente: eso lo garantiza el caso
     * de uso dentro de la transacción.
     */
    public static function forLeg(
        BillableLeg $leg,
        ChargeDocument $document,
        float $originalAmount,
        FrozenExchangeRate $rate,
        ?string $notes = null,
    ): self {
        if (! in_array($document->modality(), self::LEG_MODALITIES, true)) {
            throw new DomainException('Elegí cómo se formaliza el cobro del tramo.');
        }
        if ($leg->customerId() === null) {
            throw new DomainException('El tramo no tiene cliente: cargalo antes de formalizar el cobro.');
        }
        if (! $leg->tripFinished()) {
            throw new DomainException('Solo se formalizan tramos de viajes finalizados.');
        }

        return new self($leg->customerId(), $leg, $document, $originalAmount, $rate, self::clean($notes));
    }

    /**
     * Saldo con el que el cliente abre la cuenta corriente: a cobrar (Debe) o a
     * favor del cliente (Haber). Es uno solo por cliente mientras esté vigente.
     */
    public static function openingBalance(
        int $customerId,
        string $side,
        ChargeDocument $document,
        float $originalAmount,
        FrozenExchangeRate $rate,
        ?string $notes = null,
    ): self {
        if ($document->modality() !== self::SALDO_INICIAL) {
            throw new DomainException('El saldo inicial se registra con su propia modalidad.');
        }

        return new self($customerId, null, $document, $originalAmount, $rate, self::clean($notes), $side);
    }

    public function side(): string
    {
        return $this->side;
    }

    /** Asiento que este cargo agrega al libro en ARS. */
    public function debitArs(): float
    {
        return $this->side === self::DEBE ? $this->amountArs : 0.0;
    }

    public function creditArs(): float
    {
        return $this->side === self::HABER ? $this->amountArs : 0.0;
    }

    public function customerId(): int
    {
        return $this->customerId;
    }

    public function leg(): ?BillableLeg
    {
        return $this->leg;
    }

    public function document(): ChargeDocument
    {
        return $this->document;
    }

    public function modality(): string
    {
        return $this->document->modality();
    }

    public function originalAmount(): float
    {
        return $this->originalAmount;
    }

    public function rate(): FrozenExchangeRate
    {
        return $this->rate;
    }

    public function amountArs(): float
    {
        return $this->amountArs;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    /**
     * Clave de idempotencia: un tramo tiene como máximo un cargo vigente y un
     * cliente, un solo saldo inicial vigente.
     */
    public function activeKey(): string
    {
        return $this->leg === null
            ? 'SALDO_INICIAL:' . $this->customerId
            : 'TRAMO:' . $this->leg->legId();
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
