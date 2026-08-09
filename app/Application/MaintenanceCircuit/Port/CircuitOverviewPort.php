<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit\Port;

interface CircuitOverviewPort
{
    /**
     * @param list<int>|null $branchIds Null means every branch in the company.
     *
     * @return array<string, mixed>
     */
    public function fetch(int $companyId, ?array $branchIds): array;
}
