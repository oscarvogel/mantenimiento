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
        return $this->paidFromCash;
    }

    public function receipt(): ?string
    {
        return $this->receipt;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }
}
