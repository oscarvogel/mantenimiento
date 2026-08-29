<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\Port;

use App\Application\Identity\ActorContext;

interface WorkOrderDashboardReadModel
{
    /**
     * @param array{q?:string,status?:string,branch_id?:int|null,owner_id?:int|null,attention?:string} $filters
     * @return array<string,mixed>
     */
    public function search(ActorContext $actor, array $filters, int $page, int $perPage): array;
}
