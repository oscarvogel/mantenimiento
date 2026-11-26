<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DateTimeImmutable;
use DomainException;

/**
 * Cobranza de un cliente: el hecho económico de un pago (#588).
 *
 * Un pago se registra **en su propia moneda**, con la cotización congelada del
 * momento del cobro. Puede ser un anticipo (queda a favor del cliente, sin
 * imputar) o un pago que se imputa contra uno o varios documentos de deuda de
 * la misma moneda.
 *
 * El pago no es un recibo: no lleva numeración, adjuntos ni datos de cheque.
 * Es la proyección económica del cobro sobre el libro. El recibo y el medio de
 * pago (#536) se apoyan sobre esta capa.
 *
 * El estado_pending existe porque la política de cheques del cliente diferiría
 * el impacto en la cuenta corriente: una transferencia reduce la deuda al
 * verificarla, no al anotarse. Anular un pago restituye la deuda con
 * contraasiento y revierte las diferencias de cambio asociadas.
 */
final class CustomerPayment
{
    public const MEDIO_EFECTIVO = 'EFECTIVO';
    public const MEDIO_TRANSFERENCIA = 'TRANSFERENCIA';
    public const MEDIO_CHEQUE = 'CHEQUE';
    public const MEDIO_E_CHEQ = 'E_CHEQ';

    public const MEDIOS = [
        self::MEDIO_EFECTIVO,
        self::MEDIO_TRANSFERENCIA,
        self::MEDIO_CHEQUE,
        self::MEDIO_E_CHEQ,
    ];

    public const MEDIO_LABELS = [
        self::MEDIO_EFECTIVO => 'Efectivo',
        self::MEDIO_TRANSFERENCIA => 'Transferencia',
        self::MEDIO_CHEQUE => 'Cheque',
        self::MEDIO_E_CHEQ => 'eCheq',
    ];

    /** El pago está anotado pero todavía no impacta en el libro. */
    public const PENDIENTE = 'PENDIENTE';

    /** El pago impacta en el libro. */
    public const VIGENTE = 'VIGENTE';

    /** El pago fue anulado: su contraasiento restituye la deuda. */
    public const ANULADO = 'ANULADO';

    public function __construct(
        private readonly int $customerId,
        private readonly DateTimeImmutable $date,
        private readonly Currency $currency,
        private readonly int $amountMinor,
        private readonly FrozenExchangeRate $rate,
        private readonly string $medium,
        private readonly string $state = self::VIGENTE,
        private readonly ?string $reference = null,
        private readonly ?string $notes = null,
    ) {
        if ($customerId <= 0) {
            throw new DomainException('La cobranza necesita un cliente.');
        }
        if ($amountMinor <= 0) {
            throw new DomainException('El importe de la cobranza debe ser mayor que cero.');
        }
        if (! in_array($medium, self::MEDIOS, true)) {
            throw new DomainException('El medio de pago no es válido.');
        }
        if (! in_array($state, [self::PENDIENTE, self::VIGENTE, self::ANULADO], true)) {
            throw new DomainException('El estado de la cobranza no es válido.');
        }
        if ($rate->currency() !== $currency->code()) {
            throw new DomainException('La cotización no corresponde a la moneda de la cobranza.');
        }
        if ($reference !== null && mb_strlen($reference) > 100) {
            throw new DomainException('La referencia admite hasta 100 caracteres.');
        }
        if ($notes !== null && mb_strlen($notes) > 500) {
            throw new DomainException('Las observaciones admiten hasta 500 caracteres.');
        }
    }

    public function customerId(): int
    {
        return $this->customerId;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /** Importe cobrado en su moneda original, en unidades menores. */
    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function rate(): FrozenExchangeRate
    {
        return $this->rate;
    }

    public function medium(): string
    {
        return $this->medium;
    }

    public function mediumLabel(): string
    {
        return self::MEDIO_LABELS[$this->medium] ?? $this->medium;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    /** Equivalente histórico del cobro en pesos, a la cotización congelada. */
    public function amountArsMinor(): int
    {
        return Money::multiplyByRate($this->amountMinor, $this->rate->rate());
    }

    /** El pago ya impacta en el libro. */
    public function isEffective(): bool
    {
        return $this->state === self::VIGENTE;
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDIENTE;
    }

    public function isAnnulled(): bool
    {
        return $this->state === self::ANULADO;
    }

    /** El pago pasa a impactar en el libro (acreditación). */
    public function credit(): self
    {
        if ($this->state === self::ANULADO) {
            throw new DomainException('La cobranza está anulada: no se puede acreditar.');
        }
        if ($this->state === self::VIGENTE) {
            return $this;
        }

        return $this->withState(self::VIGENTE);
    }

    /** Anulación: restituye la deuda con contraasiento. */
    public function annul(): self
    {
        if ($this->state === self::ANULADO) {
            return $this;
        }

        return $this->withState(self::ANULADO);
    }

    /**
     * Clave de idempotencia del cobro. Con referencia (por ejemplo, el número
     * de un recibo) el mismo cobro no se puede registrar dos veces; sin ella,
     * cada anotación es una cobranza distinta.
     */
    public function activeKey(): ?string
    {
        $reference = $this->reference;
        if ($reference === null || trim($reference) === '') {
            return null;
        }

        return 'COBRANZA:' . $this->customerId . ':' . $this->date->format('Y-m-d') . ':'
            . $this->currency->code() . ':' . $this->amountMinor . ':' . trim($reference);
    }

    private function withState(string $state): self
    {
        return new self(
            $this->customerId,
            $this->date,
            $this->currency,
            $this->amountMinor,
            $this->rate,
            $this->medium,
            $state,
            $this->reference,
            $this->notes,
        );
    }
}
