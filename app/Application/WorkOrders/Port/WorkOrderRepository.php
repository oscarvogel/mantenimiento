<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\Port;

use App\Application\WorkOrders\WorkOrderActorScope;
use App\Domain\WorkOrders\WorkOrder;

interface WorkOrderRepository
{
    public function add(WorkOrder $workOrder, int $actorUserId): int;

    /** Debe invocarse dentro de una transacciÃ³n cuando vaya a modificarse. */
    public function findScopedForUpdate(int $workOrderId, WorkOrderActorScope $scope): ?WorkOrder;

    public function save(WorkOrder $workOrder, int $actorUserId): void;
}
