<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class TripExpense
{
    public function __construct(
        private DateTimeImmutable $date,
        private TripMoney $money,
        private ?int $categoryId = null,
        private ?int $paymentMethodId = null,
        private bool $paidFromCash = false,
        private ?string $receipt = null,
        private ?string $notes = null,
        private ?string $paidBy = null,
        private ?int $conceptId = null,
        private float $quantity = 1.0,
        private ?float $unitAmount = null,
    ) {
        if ($money->amount() <= 0) {
            throw new DomainException('El importe del gasto debe ser mayor que cero.');
        }
        if ($categoryId !== null && $categoryId <= 0) {
            throw new DomainException('La categoría del gasto no es válida.');
        }
        if ($paymentMethodId !== null && $paymentMethodId <= 0) {
            throw new DomainException('El medio de pago no es válido.');
        }
        if ($paidBy !== null && ! in_array(strtoupper($paidBy), ['EMPRESA', 'CHOFER', 'CAJA'], true)) {
            throw new DomainException('El pagador del gasto no es válido.');
        }
        if ($conceptId !== null && $conceptId <= 0) {
            throw new DomainException('El concepto del gasto no es válido.');
        }
        if ($quantity <= 0) {
            throw new DomainException('La cantidad del gasto debe ser mayor que cero.');
        }
        if ($unitAmount !== null && $unitAmount < 0) {
            throw new DomainException('El importe unitario del gasto no puede ser negativo.');
        }
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function money(): TripMoney
    {
        return $this->money;
    }

    public function categoryId(): ?int
    {
        return $this->categoryId;
    }

    public function paymentMethodId(): ?int
    {
        return $this->paymentMethodId;
    }

    public function paidFromCash(): bool
    {
        return $this->paidBy() === 'CAJA';
    }

    public function paidBy(): string
    {
        if ($this->paidBy !== null) {
            return strtoupper($this->paidBy);
        }

        return $this->paidFromCash ? 'CAJA' : 'EMPRESA';
    }

    public function receipt(): ?string
    {
        return $this->receipt;
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
