<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class TripStop
{
    public const CARGA = 'CARGA';
    public const DESCARGA = 'DESCARGA';
    public const HITO = 'HITO';

    private const TYPES = [self::CARGA, self::DESCARGA, self::HITO];

    public function __construct(
        private int $order,
        private string $type,
        private string $location,
        private ?int $customerId = null,
        private ?int $addressId = null,
        private ?DateTimeImmutable $estimatedAt = null,
        private ?string $cargoDescription = null,
        private ?string $quantity = null,
        private ?int $loadUnitId = null,
        private ?string $notes = null,
    ) {
        if ($order <= 0) {
            throw new DomainException('El orden de las paradas debe empezar en 1.');
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('El tipo de parada no es válido.');
        }
        if (trim($location) === '') {
            throw new DomainException('Cada parada necesita una ubicación.');
        }
        if ($customerId !== null && $customerId <= 0) {
            throw new DomainException('El cliente de la parada no es válido.');
        }
        if ($addressId !== null && $addressId <= 0) {
            throw new DomainException('El domicilio de la parada no es válido.');
        }
        if ($loadUnitId !== null && $loadUnitId <= 0) {
            throw new DomainException('La unidad de carga no es válida.');
        }
        if ($quantity !== null) {
            $normalized = str_replace(',', '.', trim($quantity));
            if ($normalized === '' || ! is_numeric($normalized) || (float) $normalized < 0) {
                throw new DomainException('La cantidad de la parada debe ser un número no negativo.');
            }
            $quantity = $normalized;
        }
    }

    public function order(): int
    {
        return $this->order;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function addressId(): ?int
    {
        return $this->addressId;
    }

    public function estimatedAt(): ?DateTimeImmutable
    {
        return $this->estimatedAt;
    }

    public function cargoDescription(): ?string
    {
        return $this->cargoDescription;
    }

    public function quantity(): ?string
    {
        return $this->quantity;
    }

    public function loadUnitId(): ?int
    {
        return $this->loadUnitId;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }
}
