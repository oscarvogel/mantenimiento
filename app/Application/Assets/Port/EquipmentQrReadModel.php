<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface EquipmentQrReadModel
{
    /** @param list<int>|null $branchIds @return array{id:int,codigo:string}|null */
    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?array;
}
