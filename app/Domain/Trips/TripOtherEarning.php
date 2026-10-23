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
}
