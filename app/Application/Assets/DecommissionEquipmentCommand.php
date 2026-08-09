<?php

declare(strict_types=1);

namespace App\Application\Assets;

use DateTimeImmutable;

final readonly class DecommissionEquipmentCommand
{
    public function __construct(
        public int $equipmentId,
        public DateTimeImmutable $decommissionedAt,
    ) {
    }
}
