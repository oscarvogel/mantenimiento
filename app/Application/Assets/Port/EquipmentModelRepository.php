<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

use App\Domain\Assets\EquipmentModel;

interface EquipmentModelRepository
{
    public function nameExists(int $companyId, int $brandId, int $typeId, string $name, ?int $excludingId = null): bool;
    public function add(EquipmentModel $model, int $actorUserId): int;
    public function findForUpdate(int $companyId, int $modelId): ?EquipmentModel;
    public function save(EquipmentModel $model, int $actorUserId): void;
    public function findActiveById(int $companyId, int $modelId): ?EquipmentModel;
}
