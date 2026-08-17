<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\Port;

interface WorkOrderPrintReadModel
{
    /** @param list<int>|null $branchIds @return array<string,mixed>|null */
    public function findScoped(int $companyId, ?array $branchIds, int $orderId): ?array;
}
