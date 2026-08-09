<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;

interface ReadingCorrectionRepository
{
    public function findForUpdate(int $readingId, int $companyId, int $equipmentId): ?EquipmentReading;

    public function markAnnulled(EquipmentReading $reading): void;

    public function recalculateCurrentUsage(
        int $companyId,
        int $branchId,
        int $equipmentId,
        int $actorUserId,
    ): UsageMeasurement;
}
