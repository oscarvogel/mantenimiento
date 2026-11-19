<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Reports\GetTelemetryReliabilityReport;
use App\Application\Reports\Port\ReportClock;
use App\Application\Reports\Port\TelemetryReliabilityDataReader;
use App\Application\Reports\ReportScope;
use App\Application\Reports\ReportScopeFactory;
use App\Domain\Reports\TelemetryReadingReliability;
use PHPUnit\Framework\TestCase;

final class GetTelemetryReliabilityReportTest extends TestCase
{
    public function testBuildsTheReportForTheAuthorizedBranchAndDefaultPeriod(): void
    {
        $reader = new TelemetryReliabilityDataReaderFake();
        $report = $this->handler($reader)->execute($this->actor(['reportes.ver'], [7, 9]), '9', null, null);

        self::assertSame(9, $reader->lastScope?->selectedBranchId);
        self::assertSame([9], $reader->lastScope?->branchIds);
        self::assertSame('2026-07-10', $report['filters']['from']);
        self::assertSame('2026-08-08', $report['filters']['to']);
        self::assertSame(TelemetryReadingReliability::CONFIABLE, $report['equipment'][0]['verdict']);
        self::assertSame('Obersat', $report['equipment'][0]['integrationName']);
    }

    public function testRejectsMissingPermission(): void
    {
        $this->expectException(DomainException::class);

        $this->handler(new TelemetryReliabilityDataReaderFake())
            ->execute($this->actor([], [7]), null, null, null);
    }

    public function testRejectsAPeriodBeyondSampleRetention(): void
    {
        $this->expectException(DomainException::class);

        $this->handler(new TelemetryReliabilityDataReaderFake())
            ->execute($this->actor(['reportes.ver'], [7]), null, '2026-01-01', '2026-08-08');
    }

    private function handler(TelemetryReliabilityDataReader $reader): GetTelemetryReliabilityReport
    {
        return new GetTelemetryReliabilityReport(
            $reader,
            new ReportScopeFactory(new FixedReliabilityClock()),
            new TelemetryReadingReliability(),
        );
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(3, 5, false, false, ['Consulta'], $permissions, $branches);
    }
}

final class TelemetryReliabilityDataReaderFake implements TelemetryReliabilityDataReader
{
    public ?ReportScope $lastScope = null;

    public function fetch(ReportScope $scope): array
    {
        $this->lastScope = $scope;

        return [
            'equipment' => [
                ['id' => 10, 'code' => 'CAM-01', 'plate' => 'AB123CD', 'branchName' => 'Central'],
            ],
            'readings' => [
                ['equipmentId' => 10, 'at' => new DateTimeImmutable('2026-08-01 08:00:00'), 'km' => 1000],
                ['equipmentId' => 10, 'at' => new DateTimeImmutable('2026-08-02 08:00:00'), 'km' => 1500],
                ['equipmentId' => 10, 'at' => new DateTimeImmutable('2026-08-03 08:00:00'), 'km' => 2000],
                ['equipmentId' => 10, 'at' => new DateTimeImmutable('2026-08-04 08:00:00'), 'km' => 2500],
                ['equipmentId' => 10, 'at' => new DateTimeImmutable('2026-08-05 08:00:00'), 'km' => 3000],
                ['equipmentId' => 10, 'at' => new DateTimeImmutable('2026-08-06 08:00:00'), 'km' => 3500],
                ['equipmentId' => 10, 'at' => new DateTimeImmutable('2026-08-07 08:00:00'), 'km' => 4000],
            ],
            'samples' => [
                ['equipmentId' => 10, 'integrationId' => 31, 'integrationName' => 'Obersat', 'provider' => 'wialon', 'at' => new DateTimeImmutable('2026-08-01 08:00:00'), 'km' => 51000],
                ['equipmentId' => 10, 'integrationId' => 31, 'integrationName' => 'Obersat', 'provider' => 'wialon', 'at' => new DateTimeImmutable('2026-08-02 08:00:00'), 'km' => 51500],
                ['equipmentId' => 10, 'integrationId' => 31, 'integrationName' => 'Obersat', 'provider' => 'wialon', 'at' => new DateTimeImmutable('2026-08-03 08:00:00'), 'km' => 52000],
                ['equipmentId' => 10, 'integrationId' => 31, 'integrationName' => 'Obersat', 'provider' => 'wialon', 'at' => new DateTimeImmutable('2026-08-04 08:00:00'), 'km' => 52500],
                ['equipmentId' => 10, 'integrationId' => 31, 'integrationName' => 'Obersat', 'provider' => 'wialon', 'at' => new DateTimeImmutable('2026-08-05 08:00:00'), 'km' => 53000],
                ['equipmentId' => 10, 'integrationId' => 31, 'integrationName' => 'Obersat', 'provider' => 'wialon', 'at' => new DateTimeImmutable('2026-08-06 08:00:00'), 'km' => 53500],
                ['equipmentId' => 10, 'integrationId' => 31, 'integrationName' => 'Obersat', 'provider' => 'wialon', 'at' => new DateTimeImmutable('2026-08-07 08:00:00'), 'km' => 54000],
            ],
            'branches' => [['id' => 9, 'nombre' => 'Central']],
        ];
    }
}

final class FixedReliabilityClock implements ReportClock
{
    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-08');
    }
}
