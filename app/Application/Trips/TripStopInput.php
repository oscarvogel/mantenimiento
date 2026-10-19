<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\TripStop;
use DateTimeImmutable;

final readonly class TripStopInput
{
    public function __construct(
        public string $type,
        public string $location,
        public ?int $customerId = null,
        public ?int $addressId = null,
        public ?DateTimeImmutable $estimatedAt = null,
        public ?string $cargoDescription = null,
        public ?string $quantity = null,
        public ?int $loadUnitId = null,
        public ?string $notes = null,
    ) {
    }

    public function toDomain(int $order): TripStop
    {
        return new TripStop(
            $order,
            $this->type,
            $this->location,
            $this->customerId,
            $this->addressId,
            $this->estimatedAt,
            $this->cargoDescription,
            $this->quantity,
            $this->loadUnitId,
            $this->notes,
        );
    }
}
