<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Application\Trips\TripProfitabilityFilters;

interface TripProfitabilityReadModel
{
    /** @param list<int>|null $branchIds @return list<array<string,mixed>> */
    public function rows(int $companyId, ?array $branchIds, TripProfitabilityFilters $filters): array;

    /** @param list<int>|null $branchIds @return list<array{id:int,code:string,name:string}> */
    public function branches(int $companyId, ?array $branchIds): array;

    /** @param list<int>|null $branchIds @return list<string> */
    public function domains(int $companyId, ?array $branchIds): array;
}
