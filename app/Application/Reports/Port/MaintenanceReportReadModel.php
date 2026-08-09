<?php

declare(strict_types=1);

namespace App\Application\Reports\Port;

use App\Application\Reports\ReportScope;

interface MaintenanceReportReadModel
{
    /** @return array<string, mixed> */
    public function fetch(ReportScope $scope): array;

    /** @return list<array<string, mixed>> */
    public function exportOrders(ReportScope $scope): array;
}
