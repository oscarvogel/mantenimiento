<?php
declare(strict_types=1);
namespace App\Application\Assets;
use DateTimeImmutable;
final readonly class CreateEquipmentRelationCommand
{
    public function __construct(
        public int $principalEquipmentId,
        public int $relatedEquipmentId,
        public string $type,
        public DateTimeImmutable $startedAt,
        public ?string $notes = null,
    ) {}
}
