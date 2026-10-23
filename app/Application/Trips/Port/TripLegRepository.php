<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripLeg;

interface TripLegRepository
{
    public function add(Trip $trip, TripLeg $leg, int $actorUserId): int;

    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool;
}
