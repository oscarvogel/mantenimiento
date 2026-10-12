<?php

declare(strict_types=1);

namespace Tests\Unit\Application\TheoreticalCost;

use App\Application\Identity\ActorContext;
use App\Application\TheoreticalCost\Port\TheoreticalCostScenarioStore;
use App\Application\TheoreticalCost\TheoreticalCostManager;
use App\Domain\TheoreticalCost\TheoreticalCostCalculator;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TheoreticalCostManagerTest extends TestCase
{
    public function testCalculatesCompanyWideScenarioWithoutEquipment(): void
    {
        $store = new FakeTheoreticalCostScenarioStore();
        $manager = new TheoreticalCostManager($store, new TheoreticalCostCalculator());

        $result = $manager->calculate($this->authorizedActor(), ['parametros' => []]);

        self::assertSame('parcial', $result['cobertura']);
        self::assertSame([], $store->equipmentLookups);
    }

    public function testSavesCompanyWideScenarioWithNullableEquipment(): void
    {
        $store = new FakeTheoreticalCostScenarioStore();
        $manager = new TheoreticalCostManager($store, new TheoreticalCostCalculator());

        $manager->save($this->authorizedActor(), ['nombre' => 'General', 'parametros' => ['km_mensuales' => 10000]]);

        self::assertNotNull($store->appended);
        self::assertNull($store->appended[5]);
        self::assertNull($store->appended[6]);
        self::assertNull($store->appended[7]['tractor_id']);
        self::assertNull($store->appended[7]['semi_id']);
    }

    public function testEquipmentAssociationMustBeSelectedAsPair(): void
    {
        $store = new FakeTheoreticalCostScenarioStore();
        $manager = new TheoreticalCostManager($store, new TheoreticalCostCalculator());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Seleccioná tractor y semi juntos o dejá ambos sin asociar.');

        $manager->calculate($this->authorizedActor(), ['tractor_id' => 4]);
    }

    private function authorizedActor(): ActorContext
    {
        return new ActorContext(11, 3, false, false, [], ['gerencial.costo_km.ver', 'gerencial.costo_km.editar'], [2]);
    }
}

final class FakeTheoreticalCostScenarioStore implements TheoreticalCostScenarioStore
{
    /** @var list<array{int,?array,int,int}> */
    public array $equipmentLookups = [];

    /** @var array<int,mixed>|null */
    public ?array $appended = null;

    public function listAssets(int $companyId, ?array $branchIds): array { return ['tractores' => [], 'semis' => []]; }

    public function equipmentPair(int $companyId, ?array $branchIds, int $tractorId, int $semiId): ?array
    {
        $this->equipmentLookups[] = [$companyId, $branchIds, $tractorId, $semiId];
        return ['tractor' => ['id' => $tractorId, 'sucursal_id' => 2], 'semi' => ['id' => $semiId, 'sucursal_id' => 2]];
    }

    public function listScenarios(int $companyId, ?array $branchIds, array $filters = []): array { return []; }

    public function findVersion(int $companyId, ?array $branchIds, int $scenarioId, int $versionNumber): ?array { return null; }

    public function appendVersion(int $companyId, int $userId, ?array $branchIds, ?int $scenarioId, string $name, ?int $tractorId, ?int $semiId, array $payload, array $result): array
    {
        $this->appended = [$companyId, $userId, $branchIds, $scenarioId, $name, $tractorId, $semiId, $payload, $result];
        return ['id' => 1];
    }
}
