<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripExpense;
use App\Domain\Trips\TripFuelLoad;
use App\Domain\Trips\TripOtherEarning;

interface TripCostRepository
{
    public function addFuel(int $tripId, int $companyId, int $branchId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): int;

    public function addExpense(int $tripId, int $companyId, int $branchId, TripExpense $expense, int $actorUserId, ?int $legId = null): int;

    public function addCashMovement(int $tripId, int $companyId, int $branchId, TripCashMovement $movement, int $actorUserId): int;

    public function updateFuel(int $tripId, int $companyId, int $movementId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): bool;

    public function updateExpense(int $tripId, int $companyId, int $movementId, TripExpense $expense, int $actorUserId, ?int $legId = null): bool;

    public function updateCashMovement(int $tripId, int $companyId, int $movementId, TripCashMovement $movement, int $actorUserId): bool;

    public function softDeleteFuel(int $tripId, int $companyId, int $movementId, int $actorUserId): bool;

    public function softDeleteExpense(int $tripId, int $companyId, int $movementId, int $actorUserId): bool;

    public function softDeleteCashMovement(int $tripId, int $companyId, int $movementId, int $actorUserId): bool;

    public function addOtherEarning(int $tripId, int $companyId, int $branchId, TripOtherEarning $earning, int $actorUserId, ?int $logbookEntryId = null): int;

    public function updateOtherEarning(int $tripId, int $companyId, int $movementId, TripOtherEarning $earning, int $actorUserId, ?int $logbookEntryId = null): bool;

    public function softDeleteOtherEarning(int $tripId, int $companyId, int $movementId, int $actorUserId): bool;

    public function logbookEntryIdForEarning(int $tripId, int $companyId, int $movementId): ?int;
}
