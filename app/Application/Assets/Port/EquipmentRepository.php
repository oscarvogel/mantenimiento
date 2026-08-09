<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

use App\Domain\Assets\Equipment;

interface EquipmentRepository
{
    public function codeExists(int $companyId, string $code): bool;

    public function add(Equipment $equipment, int $actorUserId): int;

    public function findForUpdate(int $equipmentId, int $companyId): ?Equipment;

    public function updateCurrentUsage(Equipment $equipment, int $actorUserId): void;
}
