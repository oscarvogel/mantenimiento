<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Domain\PreventiveMaintenance\UsoActual;

final readonly class EquipmentForPlan
{
    public function __construct(
        public int $id,
        public int $companyId,
        public int $branchId,
        public bool $active,
        public bool $tracksKilometres,
        public bool $tracksHours,
        public UsoActual $currentUsage,
        public int $equipmentTypeId = 0,
        public ?string $brand = null,
        public ?string $model = null,
    ) {
    }
}
