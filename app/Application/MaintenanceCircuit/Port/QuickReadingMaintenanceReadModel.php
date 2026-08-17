<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit\Port;

interface QuickReadingMaintenanceReadModel
{
    /**
     * @param list<int>|null $branchIds
     * @param list<int> $equipmentIds
     * @return array{
     *   noticesByPlan: array<int,array{id:int,equipmentId:int,planId:int}>,
     *   ordersByPlan: array<int,array{id:int,number:string,equipmentId:int,planId:int,status:string}>
     * }
     */
    public function actions(int $companyId, ?array $branchIds, array $equipmentIds): array;
}
