<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Port;

use DateTimeImmutable;

interface DashboardClock
{
    public function today(): DateTimeImmutable;
}
