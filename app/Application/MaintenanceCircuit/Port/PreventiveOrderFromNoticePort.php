<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit\Port;

interface PreventiveOrderFromNoticePort
{
    /** @param list<int>|null $branchIds Null means every branch in the company. */
    public function generate(
        int $companyId,
        ?array $branchIds,
        int $noticeId,
        int $responsibleUserId,
        int $actorUserId,
    ): int;
}
