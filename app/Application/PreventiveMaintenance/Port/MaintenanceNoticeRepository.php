<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

use App\Domain\PreventiveMaintenance\AvisoPlan;

interface MaintenanceNoticeRepository
{
    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?AvisoPlan;

    /** @param list<int>|null $branchIds */
    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?AvisoPlan;

    /** Avisos PENDIENTE de un ciclo concreto del plan. */
    public function pendingForCycle(int $companyId, int $planId, string $claveCiclo): array;

    public function save(AvisoPlan $notice, ?int $actorUserId): int;
}
