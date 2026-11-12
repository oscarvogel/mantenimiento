<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final class TripLeg
{
    public function __construct(
        private string $origin,
        private string $destination,
        private TripLegAmount $invoice,
        private TripLegAmount $liquidation,
        private ?float $kilometers = null,
        private ?int $customerId = null,
        private ?string $notes = null,
        private ?int $originDestinationCode = null,
        private ?float $tonnes = null,
        private ?int $originLocalityId = null,
        private ?int $destinationLocalityId = null,
        private ?DateTimeImmutable $startDate = null,
        private ?DateTimeImmutable $endDate = null,
    ) {
        $origin = trim($origin);
        $destination = trim($destination);

        if ($origin === '' || $destination === '') {
            throw new DomainException('El tramo necesita origen y destino.');
        }
        if ($kilometers !== null && $kilometers < 0) {
            throw new DomainException('Los kilómetros del tramo no pueden ser negativos.');
        }
        if ($tonnes !== null && $tonnes < 0) {
            throw new DomainException('Las toneladas del tramo no pueden ser negativas.');
        }
        if ($originDestinationCode !== null && $originDestinationCode <= 0) {
            throw new DomainException('El código de origen y destino no es válido.');
        }
        if (($originLocalityId !== null && $originLocalityId <= 0) || ($destinationLocalityId !== null && $destinationLocalityId <= 0)) {
            throw new DomainException('La localidad de origen o destino no es válida.');
        }
        if ($customerId !== null && $customerId <= 0) {
            throw new DomainException('El cliente del tramo no es válido.');
        }
        $startDate = $startDate?->setTime(0, 0);
        $endDate = $endDate?->setTime(0, 0);
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new DomainException('La fecha de fin del tramo no puede ser anterior a la fecha de inicio.');
        }

        $this->origin = $origin;
        $this->destination = $destination;
        $this->invoice = $invoice;
        $this->liquidation = $liquidation;
        $this->kilometers = $kilometers === null ? null : round($kilometers, 2);
        $this->customerId = $customerId;
        $this->notes = $notes === null ? null : (trim($notes) === '' ? null : trim($notes));
        $this->originDestinationCode = $originDestinationCode;
        $this->tonnes = $tonnes === null ? null : round($tonnes, 2);
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function destination(): string
    {
        return $this->destination;
    }

    public function invoice(): TripLegAmount
    {
        return $this->invoice;
    }

    public function liquidation(): TripLegAmount
    {
        return $this->liquidation;
    }

    public function kilometers(): ?float
    {
        return $this->kilometers;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function originDestinationCode(): ?int
    {
        return $this->originDestinationCode;
    }

    public function tonnes(): ?float
    {
        return $this->tonnes;
    }

    public function originLocalityId(): ?int
    {
        return $this->originLocalityId;
    }

    public function destinationLocalityId(): ?int
    {
        return $this->destinationLocalityId;
    }

    public function startDate(): ?DateTimeImmutable
    {
        return $this->startDate;
    }

    public function endDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function commission(float $percentage): ?float
    {
        $base = $this->liquidation->amountArs();

        return $base === null ? null : round($base * $percentage / 100, 4);
    }
}
