<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Assets\CreateEquipmentCommand;
use DateTimeImmutable;

final readonly class CreateEquipmentWithInitialReadingCommand
{
    public function __construct(
        public CreateEquipmentCommand $equipment,
        public ?DateTimeImmutable $readingRecordedAt,
        public ?int $initialKilometers,
        public int|float|string|null $initialHours,
    ) {
    }

    public function hasInitialReading(): bool
    {
        return $this->initialKilometers !== null
            || ($this->initialHours !== null && trim((string) $this->initialHours) !== '');
    }
}
