<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\ListWorkOrdersDashboard;
use App\Application\WorkOrders\Port\WorkOrderDashboardReadModel;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ListWorkOrdersDashboardTest extends TestCase
{
    public function testExecutesReadModelWithNormalizedPagination(): void
    {
        $captured = [];
        $readModel = new class($captured) implements WorkOrderDashboardReadModel {
            /** @var array<string,mixed> */
            public array $captured = [];

            public function __construct(array &$captured)
            {
                $this->captured =& $captured;
            }

            public function search(ActorContext $actor, array $filters, int $page, int $perPage): array
            {
                $this->captured = compact('actor', 'filters', 'page', 'perPage');
                return ['items' => [['id' => 31]], 'total' => 1];
            }
        };

        $actor = new ActorContext(7, 3, false, false, ['Técnico'], ['ordenes.mi_trabajo'], [2]);
        $filters = ['status' => 'EN_PROCESO'];
        $result = (new ListWorkOrdersDashboard($readModel))->execute($actor, $filters, 0, 999);

        self::assertSame(31, $result['items'][0]['id']);
        self::assertSame(1, $readModel->captured['page']);
        self::assertSame(25, $readModel->captured['perPage']);
        self::assertSame($filters, $readModel->captured['filters']);
        self::assertSame(7, $readModel->captured['actor']->userId());
    }

    public function testRejectsActorWithoutWorkOrderReadPermission(): void
    {
        $readModel = new class implements WorkOrderDashboardReadModel {
            public function search(ActorContext $actor, array $filters, int $page, int $perPage): array
            {
                self::fail('El read model no debe invocarse sin permiso.');
            }
        };
        $actor = new ActorContext(7, 3, false, true, ['Consulta'], ['equipos.ver'], []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenés permiso para consultar órdenes de trabajo.');

        (new ListWorkOrdersDashboard($readModel))->execute($actor, []);
    }
}
