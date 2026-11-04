<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class TripOtherEarning
{
    public function __construct(
        private int $employeeId,
        private DateTimeImmutable $date,
        private TripMoney $money,
        private string $concept,
        private ?string $notes = null,
        private ?int $conceptId = null,
        private float $quantity = 1.0,
        private ?float $unitAmount = null,
    ) {
        if ($employeeId <= 0) {
            throw new DomainException('El chofer del haber no es válido.');
        }
        if ($money->amount() <= 0) {
            throw new DomainException('El importe del haber debe ser mayor que cero.');
        }
        if (trim($concept) === '') {
            throw new DomainException('Indicá el concepto del haber.');
        }
        if ($conceptId !== null && $conceptId <= 0) {
            throw new DomainException('El concepto del haber no es válido.');
        }
        if ($quantity <= 0) {
            throw new DomainException('La cantidad del haber debe ser mayor que cero.');
        }
        if ($unitAmount !== null && $unitAmount < 0) {
            throw new DomainException('El importe unitario del haber no puede ser negativo.');
        }
    }

    public function employeeId(): int
    {
        return $this->employeeId;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function money(): TripMoney
    {
        return $this->money;
    }

    public function concept(): string
    {
        return trim($this->concept);
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function conceptId(): ?int
    {
        return $this->conceptId;
    }

    public function quantity(): float
    {
        return $this->quantity;
    }

    public function unitAmount(): ?float
    {
        return $this->unitAmount;
    }
}
