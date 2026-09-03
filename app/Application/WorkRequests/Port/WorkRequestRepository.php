<?php

declare(strict_types=1);

namespace App\Application\WorkRequests\Port;

interface WorkRequestRepository
{
    /** @param list<int>|null $branchIds */
    public function createScoped(
        int $companyId,
        int $equipmentId,
        ?array $branchIds,
        int $userId,
        string $description,
        string $reportedAt,
    ): ?int;
}
