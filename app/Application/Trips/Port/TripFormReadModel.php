<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripFormReadModel
{
    /** @param list<int>|null $branchIds @return array<string,mixed> */
    public function options(int $companyId, ?array $branchIds): array;
}
