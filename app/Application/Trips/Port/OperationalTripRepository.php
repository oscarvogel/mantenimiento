<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Application\Trips\TripConflict;
use App\Domain\Trips\Trip;
use DateTimeImmutable;

interface OperationalTripRepository
{
    public function nextNumber(int $companyId): int;

    public function add(Trip $trip, int $actorUserId): int;

    /** @param list<int>|null $branchIds */
    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip;

    public function save(Trip $trip, int $actorUserId): void;

    /**
     * @param list<int> $equipmentIds
     * @param list<int> $employeeIds
     * @return list<TripConflict>
     */
    public function conflicts(
        int $companyId,
        array $equipmentIds,
        array $employeeIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $excludeTripId = null,
    ): array;
}
