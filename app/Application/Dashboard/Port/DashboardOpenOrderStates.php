<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Port;

use App\Application\Identity\ActorContext;

interface DashboardOpenOrderStates
{
    /** @return array<string,int> */
    public function fetch(ActorContext $actor): array;
}
