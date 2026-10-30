<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripLocalityLookup
{
    /** @return list<array{id:int,name:string,label:string}> */
    public function search(string $query, int $limit = 30): array;

    /** @return array{id:int,name:string,label:string}|null */
    public function find(int $localityId): ?array;
}
