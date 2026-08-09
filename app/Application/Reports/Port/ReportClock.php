<?php

declare(strict_types=1);

namespace App\Application\Reports\Port;

use DateTimeImmutable;

interface ReportClock
{
    public function today(): DateTimeImmutable;
}
