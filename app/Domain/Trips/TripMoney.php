<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DomainException;

final readonly class TripMoney
{
    public function __construct(
        private float $amount,
        private string $currency = 'ARS',
        private float $exchangeRate = 1.0,
    ) {
        if ($amount < 0) {
            throw new DomainException('El importe no puede ser negativo.');
        }
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda debe tener tres letras.');
        }
        if ($exchangeRate <= 0) {
            throw new DomainException('La cotización debe ser mayor que cero.');
        }
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return strtoupper(trim($this->currency));
    }

    public function exchangeRate(): float
    {
        return $this->exchangeRate;
    }

    public function amountArs(): float
    {
        return $this->currency() === 'ARS' ? $this->amount : $this->amount * $this->exchangeRate;
    }
}
