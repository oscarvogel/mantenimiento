<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

/**
 * Lo que la cuenta corriente necesita saber de un tramo de Viajes para
 * formalizar su cobro. Viene por un contrato de lectura: este contexto no
 * conoce el agregado Viaje ni escribe sus tablas.
 *
 * El flete de facturación y su cotización son la estimación cargada en el
 * viaje: sirven de valor sugerido, pero no fijan la deuda.
 */
final readonly class BillableLeg
{
    public function __construct(
        private int $legId,
        private int $tripId,
        private int $branchId,
        private ?int $customerId,
        private bool $tripFinished,
        private ?float $estimatedAmount,
        private string $estimatedCurrency,
        private ?float $estimatedRate,
        private ?float $estimatedArs,
    ) {
    }

    public function legId(): int
    {
        return $this->legId;
    }

    public function tripId(): int
    {
        return $this->tripId;
    }

    public function branchId(): int
    {
        return $this->branchId;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function tripFinished(): bool
    {
        return $this->tripFinished;
    }

    public function estimatedAmount(): ?float
    {
        return $this->estimatedAmount;
    }

    public function estimatedCurrency(): string
    {
        return $this->estimatedCurrency;
    }

    public function estimatedRate(): ?float
    {
        return $this->estimatedRate;
    }

    public function estimatedArs(): ?float
    {
        return $this->estimatedArs;
    }
}
