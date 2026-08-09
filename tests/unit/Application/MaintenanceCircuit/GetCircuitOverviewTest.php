<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\GetCircuitOverview;
use App\Application\MaintenanceCircuit\Port\CircuitOverviewPort;
use CodeIgniter\Test\CIUnitTestCase;

final class GetCircuitOverviewTest extends CIUnitTestCase
{
    public function testAdministratorQueriesAllBranchesOfItsCompany(): void
    {
        $port = new class implements CircuitOverviewPort {
            public array $scope = [];

            public function fetch(int $companyId, ?array $branchIds): array
            {
                $this->scope = [$companyId, $branchIds];

                return ['equipments' => []];
            }
        };
        $actor = new ActorContext(1, 7, false, true, ['Administrador'], ['equipos.ver'], []);

        $result = (new GetCircuitOverview($port))->execute($actor);

        $this->assertSame(['equipments' => []], $result);
        $this->assertSame([7, null], $port->scope);
    }

    public function testRestrictedUserPassesOnlyAssignedBranches(): void
    {
        $port = new class implements CircuitOverviewPort {
            public array $scope = [];

            public function fetch(int $companyId, ?array $branchIds): array
            {
                $this->scope = [$companyId, $branchIds];

                return [];
            }
        };
        $actor = new ActorContext(2, 7, false, false, ['Tecnico'], ['ordenes.mi_trabajo'], [4, 8]);

        (new GetCircuitOverview($port))->execute($actor);

        $this->assertSame([7, [4, 8]], $port->scope);
    }

    public function testRejectsSuperAdministratorOutsideTenant(): void
    {
        $port = new class implements CircuitOverviewPort {
            public function fetch(int $companyId, ?array $branchIds): array
            {
                return [];
            }
        };

        $this->expectException(DomainException::class);
        (new GetCircuitOverview($port))->execute(new ActorContext(1, null, true, false, [], [], []));
    }
}
