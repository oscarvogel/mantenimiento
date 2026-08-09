<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface EquipmentReadModel
{
    /**
     * @param list<int>|null $branchIds null means every branch in the company
     * @return array<string, mixed>|null
     */
    public function findDetails(
        int $companyId,
        int $equipmentId,
        ?array $branchIds,
        int $transferPage,
        int $transfersPerPage,
        int $relationPage = 1,
        int $relationsPerPage = 20,
    ): ?array;

    /**
     * @param list<int>|null $branchIds null means every active branch in the company
     * @return list<array{id:int, codigo:string, nombre:string}>
     */
    public function listAvailableBranches(int $companyId, ?array $branchIds): array;
}
