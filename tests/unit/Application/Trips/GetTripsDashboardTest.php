<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GetTripsDashboard;
use App\Application\Trips\Port\TripDashboardReadModel;
use App\Application\Trips\TripDashboardFilters;
use PHPUnit\Framework\TestCase;

final class GetTripsDashboardTest extends TestCase
{
    public function testBuildsSummaryMonthlyAndDomainAggregates(): void
    {
        $useCase = new GetTripsDashboard(new FakeTripDashboardReadModel(
            $this->rows(),
            [['id' => 10, 'code' => 'AR', 'name' => 'Argentina']],
            ['AC 532 DD', 'IUU5G88'],
        ));

        $payload = $useCase->execute($this->actor(), new TripDashboardFilters());
        $summary = $payload['summary'];

        self::assertSame(4, $summary['trips']);
        self::assertSame(100, $summary['km']);
        self::assertSame(1, $summary['km_trips']);
        self::assertSame(25.0, $summary['km_coverage']);
        self::assertSame(120.0, $summary['liters']);
        self::assertSame(3, $summary['fuel_trips']);
        self::assertSame(75.0, $summary['fuel_coverage']);
        self::assertSame(120.0, $summary['liters_per_100']);
        self::assertSame(1, $summary['missing_readings']);
        self::assertSame(1, $summary['reversed']);
        self::assertSame(1, $summary['outliers']);

        self::assertSame(['2022-01', '2022-02', '2022-03'], array_column($payload['monthly'], 'month'));
        self::assertSame('AC 532 DD', $payload['domains'][0]['domain']);
        self::assertSame(80.0, $payload['domains'][0]['liters_per_100']);
        self::assertNull($payload['domains'][1]['liters_per_100']);
        self::assertCount(5, $payload['quality']);
    }

    public function testRejectsActorWithoutPermission(): void
    {
        $useCase = new GetTripsDashboard(new FakeTripDashboardReadModel($this->rows()));
        $actor = new ActorContext(1, 5, false, true, [], [], []);

        $this->expectException(DomainException::class);
        $useCase->execute($actor, new TripDashboardFilters());
    }

    public function testRejectsSuperAdmin(): void
    {
        $useCase = new GetTripsDashboard(new FakeTripDashboardReadModel($this->rows()));
        $actor = new ActorContext(1, null, true, false, ['Superadministrador'], ['viajes.ver'], []);

        $this->expectException(DomainException::class);
        $useCase->execute($actor, new TripDashboardFilters());
    }

    public function testActorWithoutBranchesGetsEmptyDashboard(): void
    {
        $readModel = new FakeTripDashboardReadModel($this->rows());
        $useCase = new GetTripsDashboard($readModel);
        $actor = new ActorContext(1, 5, false, false, [], ['viajes.ver'], []);

        $payload = $useCase->execute($actor, new TripDashboardFilters());

        self::assertSame(0, $payload['summary']['trips']);
        self::assertNull($payload['summary']['liters_per_100']);
        self::assertSame(0, $readModel->tripsCalls);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, true, [], ['viajes.ver'], []);
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return [
            ['id' => 1, 'sucursal_id' => 10, 'dominio' => 'AC 532 DD', 'numero_viaje' => 1, 'fecha_salida' => '2022-01-13', 'fecha_regreso' => '2022-01-14', 'km_salida' => 100, 'km_regreso' => 200, 'litros' => 50.0, 'cargas' => 1],
            ['id' => 2, 'sucursal_id' => 10, 'dominio' => 'AC 532 DD', 'numero_viaje' => 2, 'fecha_salida' => '2022-02-10', 'fecha_regreso' => null, 'km_salida' => 100, 'km_regreso' => null, 'litros' => 30.0, 'cargas' => 1],
            ['id' => 3, 'sucursal_id' => 20, 'dominio' => 'IUU5G88', 'numero_viaje' => 1, 'fecha_salida' => '2022-02-11', 'fecha_regreso' => '2022-02-12', 'km_salida' => 200, 'km_regreso' => 100, 'litros' => 0.0, 'cargas' => 0],
            ['id' => 4, 'sucursal_id' => 20, 'dominio' => 'IUU5G88', 'numero_viaje' => 2, 'fecha_salida' => '2022-03-01', 'fecha_regreso' => '2022-03-02', 'km_salida' => 0, 'km_regreso' => 20_000, 'litros' => 40.0, 'cargas' => 2],
        ];
    }
}

final class FakeTripDashboardReadModel implements TripDashboardReadModel
{
    public int $tripsCalls = 0;

    /** @param list<array<string,mixed>> $rows @param list<array{id:int,code:string,name:string}> $branches @param list<string> $domains */
    public function __construct(
        private readonly array $rows = [],
        private readonly array $branches = [],
        private readonly array $domains = [],
    ) {
    }

    public function trips(int $companyId, ?array $branchIds, TripDashboardFilters $filters): array
    {
        $this->tripsCalls++;

        return $this->rows;
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        return $this->branches;
    }

    public function domains(int $companyId, ?array $branchIds): array
    {
        return $this->domains;
    }
}
