<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\DashboardClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemDashboardClock implements DashboardClock
{
    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }
}
