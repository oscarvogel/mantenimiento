<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

use App\Application\PreventiveMaintenance\PreventivePlanListItem;

interface PreventivePlanReadModel
{
    /** @param list<int>|null $branchIds @return list<PreventivePlanListItem> */
    public function listActive(int $companyId, ?array $branchIds): array;

    /** @param list<int>|null $branchIds @return list<array<string,mixed>> */
    public function listActiveEquipment(int $companyId, ?array $branchIds): array;

    /** @return list<array<string,mixed>> */
    public function listActiveServiceTypes(int $companyId): array;

    /** @param list<int>|null $branchIds @return list<array<string,mixed>> */
    public function listActiveBranches(int $companyId, ?array $branchIds): array;

    /** @return list<array<string,mixed>> */
    public function listTemplateDefaults(int $companyId): array;
}
