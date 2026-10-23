<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripDetailReadModel
{
    /**
     * @param list<int>|null $branchIds null significa todas las sucursales de la empresa
     * @return array<string,mixed>|null viaje no visible para este alcance
     */
    public function find(int $companyId, ?array $branchIds, int $tripId): ?array;
}
