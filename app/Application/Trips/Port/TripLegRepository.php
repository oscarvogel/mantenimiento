<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Domain\Trips\TripLeg;

interface TripLegRepository
{
    public function add(int $companyId, int $branchId, int $tripId, TripLeg $leg, int $actorUserId): int;

    public function update(int $companyId, int $branchId, int $tripId, int $legId, TripLeg $leg, int $actorUserId): void;

    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool;

    /** @return list<int> Números de orden de los tramos a los que les falta alguno de los dos fletes. */
    public function ordersWithMissingAmounts(int $companyId, int $tripId): array;
}
