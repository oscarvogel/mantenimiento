<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface EquipmentListReadModel
{
    /**
     * @param list<int>|null $branchIds
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function search(
        int $companyId,
        ?array $branchIds,
        ?string $query,
        ?int $typeId,
        ?int $brandId,
        ?int $branchId,
        ?string $status,
        int $page,
        int $perPage,
    ): array;
}
