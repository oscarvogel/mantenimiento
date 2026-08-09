<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use App\Domain\Measurement\EquipmentReading;

interface ReadingRepository
{
    public function append(EquipmentReading $reading): int;
}
