<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Application\Identity\ActorContext;
use App\Application\Reports\Port\MaintenanceReportReadModel;
use App\Application\Reports\Port\ReportClock;

final class GetMaintenanceReport
{
    private readonly ReportScopeFactory $scopes;

    public function __construct(
        private readonly MaintenanceReportReadModel $readModel,
        ReportClock $clock,
    ) {
        $this->scopes = new ReportScopeFactory($clock);
    }

    /** @return array<string, mixed> */
    public function execute(
        ActorContext $actor,
        ?string $branchId,
        ?string $from,
        ?string $to,
        int $page = 1,
        int $perPage = 10,
    ): array {
        $scope = $this->scopes->build($actor, $branchId, $from, $to, $page, $perPage);
        $report = $this->readModel->fetch($scope);
        $report['filters'] = [
            'branchId' => $scope->selectedBranchId,
            'from' => $scope->from->format('Y-m-d'),
            'to' => $scope->to->format('Y-m-d'),
            'perPage' => $scope->perPage,
        ];

        return $report;
    }

    /** @return list<array<string, mixed>> */
    public function export(
        ActorContext $actor,
        ?string $branchId,
        ?string $from,
        ?string $to,
    ): array {
        return $this->readModel->exportOrders($this->scopes->build($actor, $branchId, $from, $to, 1, 100));
    }
}
