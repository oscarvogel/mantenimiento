<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Application\Trips\OperationalTripFilters;

interface OperationalTripReadModel
{
    /** @param list<int>|null $branchIds @return list<array{id:int,code:string,name:string}> */
    public function branches(int $companyId, ?array $branchIds): array;

    /**
     * @param list<int>|null $branchIds
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function list(int $companyId, ?array $branchIds, OperationalTripFilters $filters, int $page, int $perPage): array;

    /** @param list<int>|null $branchIds @return array<string,mixed>|null */
    public function find(int $companyId, ?array $branchIds, int $tripId): ?array;
}
