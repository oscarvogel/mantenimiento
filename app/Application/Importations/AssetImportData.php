<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class AssetImportData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $branchId,
        public readonly int $equipmentTypeId,
        public readonly string $code,
        public readonly ?string $plate,
        public readonly ?int $brandId,
        public readonly ?int $modelId,
        public readonly ?int $year,
        public readonly ?string $chassis,
        public readonly ?string $engine,
        public readonly string $registeredAt,
        public readonly ?string $notes,
        public readonly int $actorUserId,
        public readonly int $importId,
    ) {
    }
}
