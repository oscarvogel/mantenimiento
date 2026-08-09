<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit\Port;

interface PreventiveOrderClosurePort
{
    /**
     * The adapter must close the order and its task, register the output reading,
     * update the equipment snapshot and recalculate the plan in one transaction.
     *
     * @param list<int>|null       $branchIds Null means every branch in the company.
     * @param array<string, mixed> $closure
     *
     * @return array<string, mixed>
     */
    public function close(
        int $companyId,
        ?array $branchIds,
        int $orderId,
        array $closure,
        int $actorUserId,
    ): array;
}
