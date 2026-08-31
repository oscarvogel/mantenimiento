<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;
use DateTimeImmutable;

interface WorkOrderReadingCorrectionSynchronizer
{
    public function synchronizeFinalizedWorkOrder(
        EquipmentReading $original,
        UsageMeasurement $replacement,
        int $correctionReadingId,
        int $actorUserId,
        string $reason,
        ?string $notes,
        DateTimeImmutable $correctedAt,
    ): void;
}
