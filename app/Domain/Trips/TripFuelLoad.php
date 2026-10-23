<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class TripFuelLoad
{
    public function __construct(
        private DateTimeImmutable $date,
        private float $liters,
        private TripMoney $money,
        private ?int $odometer = null,
        private ?string $station = null,
        private ?string $receipt = null,
        private ?string $notes = null,
    ) {
        if ($liters <= 0) {
            throw new DomainException('Los litros de combustible deben ser mayores que cero.');
        }
        if ($odometer !== null && $odometer < 0) {
            throw new DomainException('El kilometraje no puede ser negativo.');
        }
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function liters(): float
    {
        return $this->liters;
    }

    public function money(): TripMoney
    {
        return $this->money;
    }

    public function odometer(): ?int
    {
        return $this->odometer;
    }

    public function station(): ?string
    {
        return $this->station;
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
