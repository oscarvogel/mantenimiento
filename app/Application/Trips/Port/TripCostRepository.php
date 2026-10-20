<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripExpense;
use App\Domain\Trips\TripFuelLoad;

interface TripCostRepository
{
    public function addFuel(int $tripId, int $companyId, int $branchId, TripFuelLoad $load, int $actorUserId): int;

    public function addExpense(int $tripId, int $companyId, int $branchId, TripExpense $expense, int $actorUserId): int;

    public function addCashMovement(int $tripId, int $companyId, int $branchId, TripCashMovement $movement, int $actorUserId): int;
}
