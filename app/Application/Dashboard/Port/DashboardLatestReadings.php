<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Port;

use App\Application\Identity\ActorContext;

interface DashboardLatestReadings
{
    /** @return array<int, string> */
    public function byEquipment(ActorContext $actor): array;
}
