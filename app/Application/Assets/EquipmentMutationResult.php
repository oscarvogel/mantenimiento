<?php

declare(strict_types=1);

namespace App\Application\Assets;

final readonly class EquipmentMutationResult
{
    public function __construct(
        public int $equipmentId,
        public int $companyId,
        public int $branchId,
        public string $code,
        public string $status,
    ) {
    }
}
