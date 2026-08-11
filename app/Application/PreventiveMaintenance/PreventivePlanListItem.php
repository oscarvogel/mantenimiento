<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;

final readonly class PreventivePlanListItem
{
    public function __construct(
        public PlanMantenimiento $plan,
        public UsoActual $currentUsage,
        public string $equipmentCode,
        public ?string $equipmentPlate,
        public int $branchId,
        public string $branchCode,
        public string $branchName,
        public string $equipmentTypeName,
        public string $serviceName,
    ) {
    }
}
