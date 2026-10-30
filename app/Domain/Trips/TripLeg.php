<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DomainException;

final class TripLeg
{
    public function __construct(
        private string $origin,
        private string $destination,
        private float $invoiceAmount,
        private float $liquidationAmount,
        private ?float $kilometers = null,
        private ?int $customerId = null,
        private string $currency = 'ARS',
        private float $exchangeRate = 1.0,
        private ?string $notes = null,
        private ?int $originDestinationCode = null,
        private ?float $tonnes = null,
        private ?int $originLocalityId = null,
        private ?int $destinationLocalityId = null,
    ) {
        $origin = trim($origin);
        $destination = trim($destination);
        $currency = strtoupper(trim($currency));

        if ($origin === '' || $destination === '') {
            throw new DomainException('El tramo necesita origen y destino.');
        }
        if ($invoiceAmount < 0 || $liquidationAmount < 0 || ($kilometers !== null && $kilometers < 0) || ($tonnes !== null && $tonnes < 0)) {
            throw new DomainException('Los importes, kilómetros y toneladas del tramo no pueden ser negativos.');
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
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda del tramo no es válida.');
        }
        if ($exchangeRate <= 0) {
            throw new DomainException('La cotización del tramo debe ser mayor que cero.');
        }

        $this->origin = $origin;
        $this->destination = $destination;
        $this->invoiceAmount = round($invoiceAmount, 4);
        $this->liquidationAmount = round($liquidationAmount, 4);
        $this->kilometers = $kilometers === null ? null : round($kilometers, 2);
        $this->customerId = $customerId;
        $this->currency = $currency;
        $this->exchangeRate = $exchangeRate;
        $this->notes = $notes === null ? null : (trim($notes) === '' ? null : trim($notes));
        $this->originDestinationCode = $originDestinationCode;
        $this->tonnes = $tonnes === null ? null : round($tonnes, 2);
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function destination(): string
    {
        return $this->destination;
    }

    public function invoiceAmount(): float
    {
        return $this->invoiceAmount;
    }

    public function liquidationAmount(): float
    {
        return $this->liquidationAmount;
    }

    public function invoiceAmountArs(): float
    {
        return round($this->invoiceAmount * $this->exchangeRate, 4);
    }

    public function liquidationAmountArs(): float
    {
        return round($this->liquidationAmount * $this->exchangeRate, 4);
    }

    public function kilometers(): ?float
    {
        return $this->kilometers;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function exchangeRate(): float
    {
        return $this->exchangeRate;
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

    public function commission(float $percentage): float
    {
        return round($this->liquidationAmountArs() * $percentage / 100, 4);
    }
}
