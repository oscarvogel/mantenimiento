<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

use App\Application\PreventiveMaintenance\EquipmentForPlan;

interface PreventiveAssetGateway
{
    /** @param list<int>|null $branchIds */
    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?EquipmentForPlan;
}
