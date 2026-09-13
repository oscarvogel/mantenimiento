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

    /** @param list<int>|null $branchIds @param array<string,mixed> $filters */
    public function listScoped(
        int $companyId,
        ?array $branchIds,
        array $filters,
        int $page,
        int $perPage,
        ?int $reportedBy,
    ): array;

    /** @param list<int>|null $branchIds */
    public function reviewScoped(
        int $companyId,
        ?array $branchIds,
        int $requestId,
        string $status,
        ?string $reason,
        int $reviewedBy,
        ?int $groupRequestId,
    ): bool;
}
