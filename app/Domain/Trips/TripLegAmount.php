<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DomainException;

/**
 * Importe de un flete con su moneda y su cotización a pesos.
 *
 * El importe puede no estar cargado todavía: los fletes son opcionales mientras
 * el viaje está abierto. La moneda y la cotización, en cambio, siempre existen:
 * cada importe lleva su propia moneda, así que el flete de liquidación puede
 * cargarse en pesos aunque el de facturación esté en dólares o reales.
 */
final readonly class TripLegAmount
{
    public function __construct(
        private ?float $amount,
        private string $currency = 'ARS',
        private float $exchangeRate = 1.0,
    ) {
        if ($amount !== null && $amount < 0) {
            throw new DomainException('El importe del flete no puede ser negativo.');
        }
        if (preg_match('/^[A-Z]{3}$/', strtoupper(trim($currency))) !== 1) {
            throw new DomainException('La moneda del flete debe tener tres letras.');
        }
        if ($exchangeRate <= 0) {
            throw new DomainException('La cotización del flete debe ser mayor que cero.');
        }
    }

    public function amount(): ?float
    {
        return $this->amount === null ? null : round($this->amount, 4);
    }

    public function currency(): string
    {
        return strtoupper(trim($this->currency));
    }

    public function exchangeRate(): float
    {
        // En pesos la cotización es 1: no hay conversión que hacer.
        return $this->currency() === 'ARS' ? 1.0 : round($this->exchangeRate, 6);
    }

    public function amountArs(): ?float
    {
        if ($this->amount === null) {
            return null;
        }

        return $this->currency() === 'ARS'
            ? round($this->amount, 4)
            : round($this->amount * $this->exchangeRate(), 4);
    }
}
