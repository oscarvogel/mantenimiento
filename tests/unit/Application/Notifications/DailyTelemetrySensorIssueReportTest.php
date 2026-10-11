<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\TelemetrySensorIssueSummaryReader;
use App\Application\Notifications\ScheduleManagementReports;
use App\Infrastructure\Notifications\CodeIgniterTelemetrySensorIssueSummaryReader;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DailyTelemetrySensorIssueReportTest extends TestCase
{
    public function testDailyReportIncludesCurrentSensorIssuesAndLinkButWeeklyDoesNot(): void
    {
        $reader = new DailyTelemetryIssuesFake();
        $db = $this->createMock(BaseConnection::class);
        $reports = new ScheduleManagementReports(new DailyTelemetryClock(), $db, 30, $reader);
        $method = (new ReflectionClass($reports))->getMethod('telemetrySensorIssueLines');

        $daily = $method->invoke($reports, 42, 'DAILY');
        $weekly = $method->invoke($reports, 42, 'WEEKLY');

        self::assertSame([
            'Sensores con problemas: 2',
            '!SENSOR|AB4990K|Voltaje (0,00): requiere revisión',
            '!SENSOR|AC532DD|Combustible (-5,00): señal fuera de rango',
            '!CTA|Revisar telemetría|' . parse_url(base_url('mantenimiento/telemetria'), PHP_URL_PATH),
        ], $daily);
        self::assertSame([], $weekly);
        self::assertSame([[42, 10]], $reader->calls);
    }

    public function testReaderTreatsMissingSnapshotTableAsNoIssues(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects(self::once())
            ->method('tableExists')
            ->with('telematia_ultima_lectura')
            ->willReturn(false);

        self::assertSame(
            ['count' => 0, 'issues' => []],
            (new CodeIgniterTelemetrySensorIssueSummaryReader($db))->forCompany(42),
        );
    }
}

final class DailyTelemetryClock implements NotificationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-10 07:00:00');
    }
}

final class DailyTelemetryIssuesFake implements TelemetrySensorIssueSummaryReader
{
    /** @var list<array{int,int}> */
    public array $calls = [];

    public function forCompany(int $companyId, int $limit = 10): array
    {
        $this->calls[] = [$companyId, $limit];

        return [
            'count' => 2,
            'issues' => [
                ['equipmentCode' => 'AB4990K', 'summary' => 'Voltaje (0,00): requiere revisión'],
                ['equipmentCode' => 'AC532DD', 'summary' => 'Combustible (-5,00): señal fuera de rango'],
            ],
        ];
    }
}
