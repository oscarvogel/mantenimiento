<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Domain\Trips\TripLogbookEntry;

interface TripLogbookRepository
{
    public function nextOrder(int $tripId, int $companyId): int;

    public function add(int $tripId, int $companyId, int $branchId, TripLogbookEntry $entry, int $actorUserId): int;

    public function update(int $tripId, int $companyId, int $entryId, TripLogbookEntry $entry, int $actorUserId): bool;

    public function softDelete(int $tripId, int $companyId, int $entryId, int $actorUserId): bool;

    public function linkedEarningId(int $tripId, int $companyId, int $entryId): ?int;
}
