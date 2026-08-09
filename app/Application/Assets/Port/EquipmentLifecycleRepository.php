<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

use App\Domain\Assets\Equipment;
use DateTimeImmutable;

interface EquipmentLifecycleRepository
{
    /** @param list<int>|null $branchIds null means every branch in the company */
    public function findScopedForUpdate(int $companyId, int $equipmentId, ?array $branchIds): ?Equipment;

    public function codeExistsExcluding(int $companyId, string $code, int $equipmentId): bool;

    public function latestTransferAtForUpdate(int $companyId, int $equipmentId): ?DateTimeImmutable;

    public function updateProfile(Equipment $equipment, int $actorUserId): void;

    public function decommission(Equipment $equipment, int $actorUserId): void;

    public function transfer(
        Equipment $equipment,
        int $originBranchId,
        DateTimeImmutable $occurredAt,
        string $reason,
        int $actorUserId,
    ): void;
}
