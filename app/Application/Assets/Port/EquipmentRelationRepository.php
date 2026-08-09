<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentRelation;

interface EquipmentRelationRepository
{
    /** @param list<int>|null $branchIds */
    public function findEquipmentForUpdate(int $companyId, int $equipmentId, ?array $branchIds): ?Equipment;
    public function hasActiveIncompatibleRelation(int $companyId, int $relatedEquipmentId, string $type): bool;
    public function add(EquipmentRelation $relation): int;
    /** @param list<int>|null $branchIds */
    public function findRelationForUpdate(int $companyId, int $relationId, ?array $branchIds): ?EquipmentRelation;
    public function finish(EquipmentRelation $relation): void;
}
