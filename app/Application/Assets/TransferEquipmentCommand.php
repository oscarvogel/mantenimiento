<?php

declare(strict_types=1);

namespace App\Application\Assets;

use DateTimeImmutable;

final readonly class TransferEquipmentCommand
{
    public function __construct(
        public int $equipmentId,
        public int $destinationBranchId,
        public DateTimeImmutable $occurredAt,
        public string $reason,
    ) {
    }
}
