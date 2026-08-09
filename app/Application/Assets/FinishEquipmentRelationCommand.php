<?php
declare(strict_types=1);
namespace App\Application\Assets;
use DateTimeImmutable;
final readonly class FinishEquipmentRelationCommand
{
    public function __construct(public int $relationId, public DateTimeImmutable $endedAt, public ?string $notes = null) {}
}
