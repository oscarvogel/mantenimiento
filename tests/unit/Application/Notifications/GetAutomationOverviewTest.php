<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Notifications\GetAutomationOverview;
use App\Application\Notifications\Port\AutomationOverviewReader;
use PHPUnit\Framework\TestCase;

final class GetAutomationOverviewTest extends TestCase
{
    public function testTenantWithPermissionReceivesOnlyItsCompanyScope(): void
    {
        $reader = new RecordingAutomationOverviewReader();
        $actor = new ActorContext(7, 5, false, false, ['Responsable de mantenimiento'], ['automatizaciones.ver'], [9]);

        $result = (new GetAutomationOverview($reader))->execute($actor);

        self::assertSame(5, $reader->companyId);
        self::assertSame([9], $reader->branchIds);
        self::assertSame([], $result['processes']);
    }

    public function testAllCompanyBranchesArePassedAsNull(): void
    {
        $reader = new RecordingAutomationOverviewReader();
        $actor = new ActorContext(7, 5, false, true, ['Administrador'], ['automatizaciones.ver'], []);

        (new GetAutomationOverview($reader))->execute($actor);

        self::assertNull($reader->branchIds);
    }

    public function testRejectsMissingPermission(): void
    {
        $this->expectException(DomainException::class);

        (new GetAutomationOverview(new RecordingAutomationOverviewReader()))->execute(
            new ActorContext(7, 5, false, true, ['Consulta'], ['notificaciones.ver'], []),
        );
    }

    public function testSuperadministratorDoesNotGainTenantAccess(): void
    {
        $this->expectException(DomainException::class);

        (new GetAutomationOverview(new RecordingAutomationOverviewReader()))->execute(
            new ActorContext(1, null, true, true, ['Superadministrador'], ['automatizaciones.ver'], []),
        );
    }
}

final class RecordingAutomationOverviewReader implements AutomationOverviewReader
{
    public ?int $companyId = null;

    /** @var list<int>|null */
    public ?array $branchIds = null;

    public function forCompany(int $companyId, ?array $branchIds): array
    {
        $this->companyId = $companyId;
        $this->branchIds = $branchIds;

        return [
            'processes' => [],
            'deliveries' => [
                'email' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null],
                'webpush' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null],
                'whatsapp' => ['pending' => 0, 'failed' => 0, 'sent' => 0, 'lastActivityAt' => null],
            ],
            'limitations' => [],
        ];
    }
}
