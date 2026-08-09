<?php

declare(strict_types=1);

namespace App\Infrastructure\Reports;

use App\Application\Reports\Port\ReportClock;
use DateTimeImmutable;

final class SystemReportClock implements ReportClock
{
    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }
}
