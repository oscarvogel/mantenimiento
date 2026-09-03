<?php

declare(strict_types=1);

namespace App\Application\DriverPortal\Port;

interface DriverPortalReadModel
{
    /**
     * @param list<int>|null $branchIds
     * @return array<string,mixed>|null
     */
    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?array;
}
