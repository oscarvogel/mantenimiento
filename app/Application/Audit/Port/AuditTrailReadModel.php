<?php

declare(strict_types=1);

namespace App\Application\Audit\Port;

interface AuditTrailReadModel
{
    /**
     * @param array{
     *     companyId?: int|null,
     *     branchId?: int|null,
     *     actorUserId?: int|null,
     *     action?: string,
     *     entity?: string,
     *     entityId?: string,
     *     dateFrom?: string|null,
     *     dateTo?: string|null,
     *     search?: string
     * } $filters
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     pages: int
     * }
     */
    public function paginate(array $filters, int $page, int $perPage): array;
}
