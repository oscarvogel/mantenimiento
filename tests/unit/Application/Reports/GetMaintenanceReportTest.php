<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Reports\GetMaintenanceReport;
use App\Application\Reports\Port\MaintenanceReportReadModel;
use App\Application\Reports\Port\ReportClock;
use App\Application\Reports\ReportScope;
use PHPUnit\Framework\TestCase;

final class GetMaintenanceReportTest extends TestCase
{
    public function testUsesLastThirtyDaysAndAuthorizedBranchScopeByDefault(): void
    {
        $readModel = new MaintenanceReportReadModelFake();
        $result = (new GetMaintenanceReport($readModel, new FixedReportClock()))->execute(
            $this->actor(['reportes.ver'], [7, 9]),
            null,
            null,
            null,
        );

        self::assertSame('2026-07-10', $result['filters']['from']);
        self::assertSame('2026-08-08', $result['filters']['to']);
        self::assertSame([7, 9], $readModel->lastScope?->branchIds);
        self::assertSame(5, $readModel->lastScope?->companyId);
    }

    public function testNarrowsReportToAnAuthorizedBranch(): void
    {
        $readModel = new MaintenanceReportReadModelFake();
        (new GetMaintenanceReport($readModel, new FixedReportClock()))->execute(
            $this->actor(['reportes.ver'], [7, 9]),
            '9',
            '2026-08-01',
            '2026-08-08',
        );

        self::assertSame([9], $readModel->lastScope?->branchIds);
        self::assertSame(9, $readModel->lastScope?->selectedBranchId);
    }

    public function testRejectsMissingPermissionAndBranchesOutsideActorScope(): void
    {
        $handler = new GetMaintenanceReport(new MaintenanceReportReadModelFake(), new FixedReportClock());

        $this->expectException(\DomainException::class);
        $handler->execute($this->actor([], [7]), null, null, null);
    }

    public function testRejectsRequestedBranchOutsideActorScope(): void
    {
        $handler = new GetMaintenanceReport(new MaintenanceReportReadModelFake(), new FixedReportClock());

        $this->expectException(\DomainException::class);
        $handler->execute($this->actor(['reportes.ver'], [7]), '9', null, null);
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(3, 5, false, false, ['Consulta'], $permissions, $branches);
    }
}

final class MaintenanceReportReadModelFake implements MaintenanceReportReadModel
{
    public ?ReportScope $lastScope = null;

    public function fetch(ReportScope $scope): array
    {
        $this->lastScope = $scope;

        return ['metrics' => [], 'orders' => []];
    }

    public function exportOrders(ReportScope $scope): array
    {
        $this->lastScope = $scope;

        return [];
    }
}

final class FixedReportClock implements ReportClock
{
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-08');
    }
}
