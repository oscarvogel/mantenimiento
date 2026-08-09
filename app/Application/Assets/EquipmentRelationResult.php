<?php
declare(strict_types=1);
namespace App\Application\Assets;
final readonly class EquipmentRelationResult
{
    public function __construct(
        public int $relationId,
        public int $principalEquipmentId,
        public int $relatedEquipmentId,
        public string $type,
        public bool $active,
    ) {}
}
