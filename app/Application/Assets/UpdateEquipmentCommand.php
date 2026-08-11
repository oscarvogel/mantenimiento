<?php

declare(strict_types=1);

namespace App\Application\Assets;

use DateTimeImmutable;

final readonly class UpdateEquipmentCommand
{
    public function __construct(
        public int $equipmentId,
        public string $code,
        public ?string $plate,
        public ?string $notes,
        public ?int $brandId = null,
        public ?int $modelId = null,
        public ?int $year = null,
        public ?string $chassis = null,
        public ?string $engine = null,
        public ?int $typeId = null,
        public ?DateTimeImmutable $registeredAt = null,
    ) {
    }
}
