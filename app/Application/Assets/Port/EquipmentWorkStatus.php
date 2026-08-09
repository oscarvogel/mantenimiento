<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface EquipmentWorkStatus
{
    public function hasOpenOrders(int $companyId, int $equipmentId): bool;
}
