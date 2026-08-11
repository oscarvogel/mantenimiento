<?php

declare(strict_types=1);

use App\Application\Assets\ListAvailableAssetBranches;
use App\Application\Assets\Port\EquipmentReadModel;
use App\Application\Identity\ActorContext;
use PHPUnit\Framework\TestCase;

final class ListAvailableAssetBranchesTest extends TestCase
{
    public function testListsOnlyBranchesInsideActorScope(): void
    {
        $readModel = new AvailableBranchesReadModelFake();
        $query = new ListAvailableAssetBranches($readModel);

        $result = $query->execute(new ActorContext(
            4, 5, false, false, ['Responsable'], ['equipos.ver', 'equipos.editar'], [7],
        ));

        self::assertSame([7], $readModel->branchIds);
        self::assertSame([['id' => 7, 'codigo' => 'TSAARG', 'nombre' => 'TSA Argentina']], $result);
    }
}

final class AvailableBranchesReadModelFake implements EquipmentReadModel
{
    /** @var list<int>|null */
    public ?array $branchIds = null;

    public function findDetails(
        int $companyId,
        int $equipmentId,
        ?array $branchIds,
        int $transferPage,
        int $transfersPerPage,
        int $relationPage = 1,
        int $relationsPerPage = 20,
    ): ?array {
        return null;
    }

    public function listAvailableBranches(int $companyId, ?array $branchIds): array
    {
        $this->branchIds = $branchIds;

        return [['id' => 7, 'codigo' => 'TSAARG', 'nombre' => 'TSA Argentina']];
    }
}
