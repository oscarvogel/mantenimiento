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
        private float $kilometers = 0.0,
        private ?int $customerId = null,
        private string $currency = 'ARS',
        private float $exchangeRate = 1.0,
        private ?string $notes = null,
    ) {
        $origin = trim($origin);
        $destination = trim($destination);
        $currency = strtoupper(trim($currency));

        if ($origin === '' || $destination === '') {
            throw new DomainException('El tramo necesita origen y destino.');
        }
        if ($invoiceAmount < 0 || $liquidationAmount < 0 || $kilometers < 0) {
            throw new DomainException('Los importes y kilómetros del tramo no pueden ser negativos.');
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
        $this->kilometers = round($kilometers, 2);
        $this->customerId = $customerId;
        $this->currency = $currency;
        $this->exchangeRate = $exchangeRate;
        $this->notes = $notes === null ? null : (trim($notes) === '' ? null : trim($notes));
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

    public function kilometers(): float
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

    public function commission(float $percentage): float
    {
        return round($this->liquidationAmountArs() * $percentage / 100, 4);
    }
}
