<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

use App\Domain\PreventiveMaintenance\PlanMantenimiento;

interface PlanMantenimientoRepository
{
    /** @param list<int>|null $branchIds Null means every branch in the company. */
    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento;

    /** @param list<int>|null $branchIds */
    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool;

    /** @param list<int>|null $branchIds
     *  @return list<PlanMantenimiento>
     */
    public function listActiveScoped(int $companyId, ?array $branchIds): array;

    public function save(PlanMantenimiento $plan, int $actorUserId): int;
}
