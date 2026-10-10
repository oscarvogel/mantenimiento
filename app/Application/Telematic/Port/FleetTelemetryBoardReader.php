<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

interface FleetTelemetryBoardReader
{
    /**
     * Returns the current snapshot for linked active equipment, scoped in SQL.
     * A null branch list means every branch in the company; an empty list
     * means that the actor has no authorized branches.
     *
     * @param list<int>|null $branchIds
     * @return array{units:list<array<string,mixed>>,branches:list<array{id:int,name:string}>}
     */
    public function read(int $companyId, ?array $branchIds): array;
}
