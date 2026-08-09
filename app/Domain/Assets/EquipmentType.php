<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use DomainException;

final class EquipmentType
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly bool $tracksKilometers,
        private readonly bool $tracksHours,
    ) {
        if ($id <= 0 || trim($name) === '') {
            throw new DomainException('El tipo de equipo no es válido.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tracksKilometers(): bool
    {
        return $this->tracksKilometers;
    }

    public function tracksHours(): bool
    {
        return $this->tracksHours;
    }
}
