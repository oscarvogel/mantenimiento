<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface EquipmentRelationStatus
{
    public function hasActiveRelations(int $companyId, int $equipmentId): bool;
}
