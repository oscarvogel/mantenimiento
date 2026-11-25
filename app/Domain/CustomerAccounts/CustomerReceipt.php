<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DateTimeImmutable;
use DomainException;

/**
 * Recibo de cobranza de un cliente (#536).
 *
 * Nace en BORRADOR y recién al confirmarse consume su número correlativo y
 * acredita los medios que correspondan. Nunca se edita ni se borra una vez
 * confirmado: se anula con un contraasiento en la cuenta corriente. El total
 * del recibo está expresado en ARS, pero cada medio conserva su moneda y su
 * cotización histórica.
 */
final class CustomerReceipt
{
    public const DRAFT = 'BORRADOR';
    public const CONFIRMED = 'CONFIRMADO';
    public const CANCELLED = 'ANULADO';

    public const LABELS = [
        self::DRAFT => 'Borrador',
        self::CONFIRMED => 'Confirmado',
        self::CANCELLED => 'Anulado',
    ];

    private int $totalMinor = 0;
    private int $imputedMinor = 0;

    /**
     * @param list<PaymentInstrument> $instruments
     * @param list<ReceiptImputation> $imputations
     */
    private function __construct(
        private int $companyId,
        private int $branchId,
        private int $customerId,
        private string $series,
        private DateTimeImmutable $issueDate,
        private DateTimeImmutable $receptionDate,
        private ?string $notes,
        private array $instruments,
        private array $imputations,
        private string $status = self::DRAFT,
        private ?int $number = null,
        private ?DateTimeImmutable $confirmedAt = null,
        private ?int $confirmedBy = null,
        private ?DateTimeImmutable $cancelledAt = null,
        private ?int $cancelledBy = null,
        private ?string $cancelReason = null,
    ) {
        if ($companyId <= 0 || $branchId <= 0 || $customerId <= 0) {
            throw new DomainException('El recibo necesita empresa, sucursal y cliente.');
        }
        $series = strtoupper(trim($series));
        if ($series === '' || mb_strlen($series) > 10) {
            throw new DomainException('La serie del recibo admite hasta 10 caracteres.');
        }
        if ($instruments === []) {
            throw new DomainException('Agregá al menos un medio de pago al recibo.');
        }
        if ($notes !== null && mb_strlen($notes) > 1000) {
            throw new DomainException('Las observaciones del recibo admiten hasta 1000 caracteres.');
        }

        $this->series = $series;
        $this->notes = self::clean($notes);
        foreach ($instruments as $instrument) {
            $this->totalMinor += $instrument->amountArsMinor();
        }
        if ($this->totalMinor <= 0) {
            throw new DomainException('El total del recibo debe ser mayor que cero.');
        }
        if ($this->sumImputationsMinor() > $this->totalMinor) {
            throw new DomainException('La suma imputada no puede superar el total del recibo.');
        }
    }

    /**
     * @param list<PaymentInstrument> $instruments
     * @param list<ReceiptImputation> $imputations
     */
    public static function draft(
        int $companyId,
        int $branchId,
        int $customerId,
        string $series,
        DateTimeImmutable $issueDate,
        DateTimeImmutable $receptionDate,
        ?string $notes,
        array $instruments,
        array $imputations,
        DateTimeImmutable $today,
    ): self {
        if ($issueDate > $today) {
            throw new DomainException('La fecha de emisión del recibo no puede ser futura.');
        }
        if ($receptionDate > $today) {
            throw new DomainException('La fecha efectiva de recepción del pago no puede ser futura.');
        }
        if ($receptionDate < $issueDate) {
            throw new DomainException('La fecha de recepción no puede ser anterior a la emisión del recibo.');
        }
        foreach ($instruments as $instrument) {
            if ($instrument->transferDate() !== null && $instrument->transferDate() > $today) {
                throw new DomainException('La fecha de la transferencia no puede ser futura.');
            }
            if ($instrument->chequeReceivedDate() !== null && $instrument->chequeReceivedDate() > $today) {
                throw new DomainException('La fecha de recepción del cheque no puede ser futura.');
            }
        }

        return new self($companyId, $branchId, $customerId, $series, $issueDate, $receptionDate, $notes, $instruments, $imputations);
    }

    /** Reconstruye el agregado desde persistencia sin revalidar datos históricos. */
    public static function reconstitute(
        int $companyId,
        int $branchId,
        int $customerId,
        string $series,
        DateTimeImmutable $issueDate,
        DateTimeImmutable $receptionDate,
        ?string $notes,
        array $instruments,
        array $imputations,
        string $status,
        ?int $number,
        ?DateTimeImmutable $confirmedAt,
        ?int $confirmedBy,
        ?DateTimeImmutable $cancelledAt,
        ?int $cancelledBy,
        ?string $cancelReason,
    ): self {
        return new self(
            $companyId,
            $branchId,
            $customerId,
            $series,
            $issueDate,
            $receptionDate,
            $notes,
            $instruments,
            $imputations,
            $status,
            $number,
            $confirmedAt,
            $confirmedBy,
            $cancelledAt,
            $cancelledBy,
            $cancelReason,
        );
    }

    /** Consume el número correlativo y confirma el recibo en una sola operación. */
    public function confirm(int $number, DateTimeImmutable $now, int $userId, ?int $creditableMinor = null): void
    {
        self::assertConfirmable($this->status);
        if ($number <= 0) {
            throw new DomainException('No se pudo reservar el número del recibo.');
        }
        if ($userId <= 0) {
            throw new DomainException('El recibo necesita un usuario responsable.');
        }
        self::assertImputationsCovered($this->sumImputationsMinor(), $creditableMinor ?? $this->creditableOnConfirmationMinor());

        $this->status = self::CONFIRMED;
        $this->number = $number;
        $this->confirmedAt = $now;
        $this->confirmedBy = $userId;
    }

    public function annul(string $reason, DateTimeImmutable $now, int $userId): void
    {
        $reason = self::assertAnnulable($this->status, $reason, $userId);

        $this->status = self::CANCELLED;
        $this->cancelledAt = $now;
        $this->cancelledBy = $userId;
        $this->cancelReason = $reason;
    }

    public static function assertConfirmable(string $status): void
    {
        if ($status !== self::DRAFT) {
            throw new DomainException('Solo se confirman recibos en borrador.');
        }
    }

    public static function assertImputationsCovered(int $imputedMinor, int $creditableMinor): void
    {
        if ($imputedMinor > $creditableMinor) {
            throw new DomainException(
                'Lo imputado supera el importe que se acredita al confirmar. '
                . 'Acreditá los medios pendientes antes de imputar esa parte, o reducí las imputaciones.',
            );
        }
    }

    /** @return string Motivo normalizado. */
    public static function assertAnnulable(string $status, string $reason, int $userId): string
    {
        if ($status === self::CANCELLED) {
            throw new DomainException('El recibo ya está anulado.');
        }
        if ($status !== self::CONFIRMED) {
            throw new DomainException('Solo se anulan recibos confirmados. Un borrador se descarta.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Indicá el motivo de la anulación (al menos 5 caracteres).');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('El motivo de la anulación admite hasta 500 caracteres.');
        }
        if ($userId <= 0) {
            throw new DomainException('La anulación necesita un usuario responsable.');
        }

        return $reason;
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    public function branchId(): int
    {
        return $this->branchId;
    }

    public function customerId(): int
    {
        return $this->customerId;
    }

    public function series(): string
    {
        return $this->series;
    }

    public function issueDate(): DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function receptionDate(): DateTimeImmutable
    {
        return $this->receptionDate;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    /** @return list<PaymentInstrument> */
    public function instruments(): array
    {
        return $this->instruments;
    }

    /** @return list<ReceiptImputation> */
    public function imputations(): array
    {
        return $this->imputations;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function number(): ?int
    {
        return $this->number;
    }

    public function displayNumber(): string
    {
        return $this->number === null
            ? 'Borrador'
            : $this->series . '-' . str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function confirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function confirmedBy(): ?int
    {
        return $this->confirmedBy;
    }

    public function cancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancelledBy(): ?int
    {
        return $this->cancelledBy;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function totalMinor(): int
    {
        return $this->totalMinor;
    }

    public function total(): float
    {
        return Money::toDecimal($this->totalMinor);
    }

    public function imputationsTotalMinor(): int
    {
        return $this->sumImputationsMinor();
    }

    public function imputationsTotal(): float
    {
        return Money::toDecimal($this->imputationsTotalMinor());
    }

    public function creditableOnConfirmationMinor(): int
    {
        $total = 0;
        foreach ($this->instruments as $instrument) {
            if ($instrument->accreditsOnConfirmation()) {
                $total += $instrument->amountArsMinor();
            }
        }

        return $total;
    }

    public function creditableOnConfirmation(): float
    {
        return Money::toDecimal($this->creditableOnConfirmationMinor());
    }

    public function pendingAccreditationMinor(): int
    {
        return $this->totalMinor - $this->creditableOnConfirmationMinor();
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'cliente_id' => $this->customerId,
            'sucursal_id' => $this->branchId,
            'serie' => $this->series,
            'numero' => $this->number,
            'numero_visible' => $this->displayNumber(),
            'fecha_emision' => $this->issueDate->format('Y-m-d'),
            'fecha_recepcion' => $this->receptionDate->format('Y-m-d'),
            'estado' => $this->status,
            'total_ars' => $this->total(),
            'imputado_ars' => $this->imputationsTotal(),
            'medios' => array_map(static fn (PaymentInstrument $instrument): array => $instrument->snapshot(), $this->instruments),
            'imputaciones' => array_map(static fn (ReceiptImputation $imputation): array => $imputation->snapshot(), $this->imputations),
            'observaciones' => $this->notes,
        ];
    }

    private function sumImputationsMinor(): int
    {
        $this->imputedMinor = 0;
        foreach ($this->imputations as $imputation) {
            $this->imputedMinor += $imputation->amountMinor();
        }

        return $this->imputedMinor;
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
