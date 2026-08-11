<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface EquipmentTypeChangeGuard
{
    public function hasOpenWorkOrders(int $companyId, int $equipmentId): bool;

    public function hasActivePlanUsingKilometers(int $companyId, int $equipmentId): bool;

    public function hasActivePlanUsingHours(int $companyId, int $equipmentId): bool;
}
