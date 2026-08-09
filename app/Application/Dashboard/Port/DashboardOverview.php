<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Port;

use App\Application\Identity\ActorContext;

interface DashboardOverview
{
    /** @return array<string, mixed> */
    public function fetch(ActorContext $actor): array;
}
