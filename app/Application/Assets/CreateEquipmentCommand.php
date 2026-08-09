<?php

declare(strict_types=1);

namespace App\Application\Assets;

use DateTimeImmutable;

final readonly class CreateEquipmentCommand
{
    public function __construct(
        public int $branchId,
        public int $typeId,
        public string $code,
        public ?string $plate,
        public DateTimeImmutable $registeredAt,
        public ?string $notes = null,
        public ?int $brandId = null,
        public ?int $modelId = null,
        public ?int $year = null,
        public ?string $chassis = null,
        public ?string $engine = null,
    ) {
    }
}
