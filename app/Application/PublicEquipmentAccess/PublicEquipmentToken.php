<?php

declare(strict_types=1);

namespace App\Application\PublicEquipmentAccess;

final readonly class PublicEquipmentToken
{
    public function __construct(
        public int $equipmentId,
        public string $plainToken,
    ) {
    }
}
