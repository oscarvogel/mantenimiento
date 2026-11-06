<?php

declare(strict_types=1);

use App\Application\Audit\GetAuditTrail;
use App\Application\Audit\Port\AuditTrailReadModel;
use App\Application\Identity\ActorContext;
use CodeIgniter\Test\CIUnitTestCase;

final class GetAuditTrailTest extends CIUnitTestCase
{
    public function testOnlyGlobalSuperAdminCanReadTheTrail(): void
    {
        $this->expectException(DomainException::class);

        (new GetAuditTrail(new RecordingAuditTrailReadModel()))->execute(
            new ActorContext(9, 7, false, true, ['Administrador'], ['auditoria.ver'], []),
            [],
        );
    }

    public function testNormalizesFiltersAndForwardsPagination(): void
    {
        $readModel = new RecordingAuditTrailReadModel();
        $actor = new ActorContext(99, null, true, false, [], [], []);

        (new GetAuditTrail($readModel))->execute($actor, [
            'companyId'   => '7',
            'branchId'    => '2',
            'actorUserId' => '9',
            'action'      => ' usuario.creado ',
            'entity'      => ' usuarios ',
            'entityId'    => ' 3 ',
            'dateFrom'    => '2026-11-01',
            'dateTo'      => '2026-11-06',
            'search'      => ' ana ',
        ], 3, 500);

        self::assertSame(3, $readModel->page);
        self::assertSame(100, $readModel->perPage);
        self::assertSame([
            'companyId'   => 7,
            'branchId'    => 2,
            'actorUserId' => 9,
            'action'      => 'usuario.creado',
            'entity'      => 'usuarios',
            'entityId'    => '3',
            'dateFrom'    => '2026-11-01',
            'dateTo'      => '2026-11-06',
            'search'      => 'ana',
        ], $readModel->filters);
    }

    public function testRejectsInvalidDateRange(): void
    {
        $this->expectException(DomainException::class);

        (new GetAuditTrail(new RecordingAuditTrailReadModel()))->execute(
            new ActorContext(99, null, true, false, [], [], []),
            ['dateFrom' => '2026-11-06', 'dateTo' => '2026-11-01'],
        );
    }

    public function testRejectsInvalidNumericFilter(): void
    {
        $this->expectException(DomainException::class);

        (new GetAuditTrail(new RecordingAuditTrailReadModel()))->execute(
            new ActorContext(99, null, true, false, [], [], []),
            ['companyId' => 'abc'],
        );
    }
}

final class RecordingAuditTrailReadModel implements AuditTrailReadModel
{
    public array $filters = [];

    public int $page = 0;

    public int $perPage = 0;

    public function paginate(array $filters, int $page, int $perPage): array
    {
        $this->filters = $filters;
        $this->page = $page;
        $this->perPage = $perPage;

        return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
    }
}
