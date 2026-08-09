<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class MeasurementImportData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $branchId,
        public readonly int $equipmentId,
        public readonly string $recordedAt,
        public readonly ?int $kilometers,
        public readonly ?string $hours,
        public readonly string $origin,
        public readonly string $sourceOrigin,
        public readonly ?string $notes,
        public readonly int $actorUserId,
        public readonly int $importId,
    ) {
    }
}
