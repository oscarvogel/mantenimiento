<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DateTimeImmutable;
use DomainException;

/**
 * Medio de pago recibido en un recibo de cobranza (#536).
 *
 * Conserva la moneda original, el importe original y la cotización histórica
 * congelada. La decisión de acreditar (impactar el Haber en la cuenta
 * corriente) es explícita: el efectivo se acredita al confirmar; una
 * transferencia solo si estaba verificada; un cheque físico o un eCheq queda
 * pendiente hasta que se acredite, porque recibirlo no equivale a tener el
 * dinero disponible.
 */
final readonly class PaymentInstrument
{
    public const EFECTIVO = 'EFECTIVO';
    public const TRANSFERENCIA = 'TRANSFERENCIA';
    public const CHEQUE = 'CHEQUE';
    public const E_CHEQ = 'E_CHEQ';

    public const KINDS = [self::EFECTIVO, self::TRANSFERENCIA, self::CHEQUE, self::E_CHEQ];

    public const LABELS = [
        self::EFECTIVO => 'Efectivo',
        self::TRANSFERENCIA => 'Transferencia bancaria',
        self::CHEQUE => 'Cheque físico',
        self::E_CHEQ => 'eCheq',
    ];

    public const PENDING = 'PENDIENTE';
    public const ACCREDITED = 'ACREDITADO';
    public const CANCELLED = 'ANULADO';

    public const VERIFICATION_PENDING = 'PENDIENTE';
    public const VERIFICATION_VERIFIED = 'VERIFICADA';
    public const VERIFICATION_CONCILIED = 'CONCILIADA';
    public const VERIFICATION_REJECTED = 'RECHAZADA';

    private function __construct(
        private string $kind,
        private FrozenExchangeRate $rate,
        private int $amountMinor,
        private ?string $bank = null,
        private ?string $account = null,
        private ?string $holder = null,
        private ?DateTimeImmutable $transferDate = null,
        private ?string $reference = null,
        private string $verification = self::VERIFICATION_PENDING,
        private ?string $chequeNumber = null,
        private ?string $drawer = null,
        private ?string $drawerTaxId = null,
        private ?DateTimeImmutable $chequeIssueDate = null,
        private ?DateTimeImmutable $chequeDueDate = null,
        private ?DateTimeImmutable $chequeReceivedDate = null,
        private ?string $notes = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new DomainException('Elegí un medio de pago válido.');
        }
        if ($amountMinor <= 0) {
            throw new DomainException('El importe de cada medio de pago debe ser mayor que cero.');
        }
        if ($notes !== null && mb_strlen($notes) > 500) {
            throw new DomainException('Las observaciones del medio de pago admiten hasta 500 caracteres.');
        }
        if ($kind === self::TRANSFERENCIA) {
            if (! in_array($verification, [self::VERIFICATION_PENDING, self::VERIFICATION_VERIFIED, self::VERIFICATION_CONCILIED, self::VERIFICATION_REJECTED], true)) {
                throw new DomainException('El estado de verificación de la transferencia no es válido.');
            }
            if ($this->clean($bank) === null) {
                throw new DomainException('Indicá el banco de la transferencia.');
            }
            if ($transferDate === null) {
                throw new DomainException('Indicá la fecha de la transferencia.');
            }
            if ($this->clean($reference) === null) {
                throw new DomainException('Indicá la referencia o el número de operación de la transferencia.');
            }
        }
        if ($kind === self::CHEQUE || $kind === self::E_CHEQ) {
            if ($this->clean($bank) === null) {
                throw new DomainException('Indicá el banco emisor del cheque.');
            }
            if ($this->clean($chequeNumber) === null) {
                throw new DomainException('Indicá el número o identificador del cheque.');
            }
            if ($this->clean($drawer) === null) {
                throw new DomainException('Indicá el librador del cheque.');
            }
            if ($chequeIssueDate === null || $chequeDueDate === null || $chequeReceivedDate === null) {
                throw new DomainException('Completá las fechas de emisión, pago y recepción del cheque.');
            }
            if ($chequeDueDate < $chequeIssueDate) {
                throw new DomainException('La fecha de pago del cheque no puede ser anterior a su emisión.');
            }
            if ($drawerTaxId !== null && preg_match('/^[0-9\-]{6,20}$/', $drawerTaxId) !== 1) {
                throw new DomainException('El CUIT del librador no tiene un formato válido.');
            }
        }
        if ($kind === self::EFECTIVO && $this->clean($reference) !== null) {
            throw new DomainException('El efectivo no lleva referencia de operación.');
        }
    }

    public static function cash(FrozenExchangeRate $rate, int $amountMinor, ?string $notes = null): self
    {
        return new self(self::EFECTIVO, $rate, $amountMinor, notes: self::cleanValue($notes));
    }

    public static function transfer(
        FrozenExchangeRate $rate,
        int $amountMinor,
        string $bank,
        ?string $account,
        ?string $holder,
        DateTimeImmutable $transferDate,
        string $reference,
        string $verification = self::VERIFICATION_PENDING,
        ?string $notes = null,
    ): self {
        return new self(
            self::TRANSFERENCIA,
            $rate,
            $amountMinor,
            bank: self::cleanValue($bank),
            account: self::cleanValue($account),
            holder: self::cleanValue($holder),
            transferDate: $transferDate,
            reference: self::cleanValue($reference),
            verification: strtoupper(trim($verification)),
            notes: self::cleanValue($notes),
        );
    }

    public static function cheque(
        string $kind,
        FrozenExchangeRate $rate,
        int $amountMinor,
        string $bank,
        string $number,
        string $drawer,
        ?string $drawerTaxId,
        DateTimeImmutable $issueDate,
        DateTimeImmutable $dueDate,
        DateTimeImmutable $receivedDate,
        ?string $notes = null,
    ): self {
        if (! in_array($kind, [self::CHEQUE, self::E_CHEQ], true)) {
            throw new DomainException('Elegí el tipo de cheque.');
        }

        return new self(
            $kind,
            $rate,
            $amountMinor,
            bank: self::cleanValue($bank),
            chequeNumber: self::cleanValue($number),
            drawer: self::cleanValue($drawer),
            drawerTaxId: self::cleanValue($drawerTaxId),
            chequeIssueDate: $issueDate,
            chequeDueDate: $dueDate,
            chequeReceivedDate: $receivedDate,
            notes: self::cleanValue($notes),
        );
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }

    public function rate(): FrozenExchangeRate
    {
        return $this->rate;
    }

    public function currency(): string
    {
        return $this->rate->currency();
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function amount(): float
    {
        return Money::toDecimal($this->amountMinor);
    }

    /** Equivalente en ARS congelado en el momento del cobro. */
    public function amountArsMinor(): int
    {
        return Money::multiplyByRate($this->amountMinor, $this->rate->rate());
    }

    public function amountArs(): float
    {
        return Money::toDecimal($this->amountArsMinor());
    }

    public function bank(): ?string
    {
        return $this->bank;
    }

    public function account(): ?string
    {
        return $this->account;
    }

    public function holder(): ?string
    {
        return $this->holder;
    }

    public function transferDate(): ?DateTimeImmutable
    {
        return $this->transferDate;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function verification(): string
    {
        return $this->verification;
    }

    public function chequeNumber(): ?string
    {
        return $this->chequeNumber;
    }

    public function drawer(): ?string
    {
        return $this->drawer;
    }

    public function drawerTaxId(): ?string
    {
        return $this->drawerTaxId;
    }

    public function chequeIssueDate(): ?DateTimeImmutable
    {
        return $this->chequeIssueDate;
    }

    public function chequeDueDate(): ?DateTimeImmutable
    {
        return $this->chequeDueDate;
    }

    public function chequeReceivedDate(): ?DateTimeImmutable
    {
        return $this->chequeReceivedDate;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function isCheque(): bool
    {
        return $this->kind === self::CHEQUE || $this->kind === self::E_CHEQ;
    }

    /**
     * Un cheque diferido no es dinero disponible y una transferencia informada
     * todavía no está acreditada. Solo estos casos impactan el libro al
     * confirmar el recibo; el resto se acredita después, una única vez.
     */
    public function accreditsOnConfirmation(): bool
    {
        return match ($this->kind) {
            self::EFECTIVO => true,
            self::TRANSFERENCIA => in_array($this->verification, [self::VERIFICATION_VERIFIED, self::VERIFICATION_CONCILIED], true),
            default => false,
        };
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'medio' => $this->kind,
            'moneda' => $this->currency(),
            'importe' => $this->amount(),
            'cotizacion' => $this->rate->rate(),
            'cotizacion_fecha' => $this->rate->date()->format('Y-m-d'),
            'cotizacion_fuente' => $this->rate->source(),
            'importe_ars' => $this->amountArs(),
            'acredita_al_confirmar' => $this->accreditsOnConfirmation(),
            'banco' => $this->bank,
            'cuenta_destino' => $this->account,
            'titular' => $this->holder,
            'fecha_transferencia' => $this->transferDate?->format('Y-m-d'),
            'referencia' => $this->reference,
            'estado_verificacion' => $this->kind === self::TRANSFERENCIA ? $this->verification : null,
            'cheque_numero' => $this->chequeNumber,
            'librador' => $this->drawer,
            'cuit_librador' => $this->drawerTaxId,
            'cheque_emision' => $this->chequeIssueDate?->format('Y-m-d'),
            'cheque_vencimiento' => $this->chequeDueDate?->format('Y-m-d'),
            'cheque_recepcion' => $this->chequeReceivedDate?->format('Y-m-d'),
            'observaciones' => $this->notes,
        ];
    }

    private static function cleanValue(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function clean(?string $value): ?string
    {
        return self::cleanValue($value);
    }
}
