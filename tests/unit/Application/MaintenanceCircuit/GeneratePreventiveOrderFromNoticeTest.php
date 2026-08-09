<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\GeneratePreventiveOrderFromNotice;
use App\Application\MaintenanceCircuit\Port\PreventiveOrderFromNoticePort;
use CodeIgniter\Test\CIUnitTestCase;

final class GeneratePreventiveOrderFromNoticeTest extends CIUnitTestCase
{
    public function testUsesTenantAndBranchScopeAndDefaultsResponsibleToActor(): void
    {
        $port = new class implements PreventiveOrderFromNoticePort {
            public array $arguments = [];

            public function generate(int $companyId, ?array $branchIds, int $noticeId, int $responsibleUserId, int $actorUserId): int
            {
                $this->arguments = func_get_args();

                return 44;
            }
        };
        $actor = new ActorContext(9, 3, false, false, ['Responsable'], ['ordenes.editar'], [7]);

        $id = (new GeneratePreventiveOrderFromNotice($port))->execute($actor, 12);

        $this->assertSame(44, $id);
        $this->assertSame([3, [7], 12, 9, 9], $port->arguments);
    }

    public function testRejectsActorWithoutPermission(): void
    {
        $port = new class implements PreventiveOrderFromNoticePort {
            public function generate(int $companyId, ?array $branchIds, int $noticeId, int $responsibleUserId, int $actorUserId): int
            {
                self::fail('The port must not be called.');
            }
        };

        $this->expectException(DomainException::class);
        (new GeneratePreventiveOrderFromNotice($port))->execute(
            new ActorContext(9, 3, false, true, ['Consulta'], ['ordenes.ver'], []),
            12,
        );
    }
}
