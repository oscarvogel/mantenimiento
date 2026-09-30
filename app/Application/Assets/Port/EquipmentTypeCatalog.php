<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

use App\Domain\Assets\EquipmentType;

interface EquipmentTypeCatalog
{
    public function findActiveById(int $typeId): ?EquipmentType;

    public function updateTracking(int $typeId, bool $tracksKilometers, bool $tracksHours): void;
}
