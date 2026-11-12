<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GetOperationalTripsDashboard;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\OperationalTripDashboardReadModel;
use PHPUnit\Framework\TestCase;

final class GetOperationalTripsDashboardTest extends TestCase
{
    public function testScopesDashboardAndCalculatesIndicatorsFromReliableData(): void
    {
        $readModel = new DashboardReadModelFake();
        $payload = (new GetOperationalTripsDashboard($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar', 'viajes.economico.ver'], [10, 20]),
            new OperationalTripFilters(from: '2026-10-01', to: '2026-10-31', branchId: 10),
        );

        self::assertSame([5, [10, 20], 10], $readModel->tripsQuery);
        self::assertSame(1, $payload['summary']['en_curso']);
        self::assertSame(1, $payload['summary']['planificados']);
        self::assertSame(1, $payload['summary']['finalizados']);
        self::assertSame(1, $payload['summary']['cancelados']);
        self::assertSame(3, $payload['summary']['total']);
        self::assertSame(2, $payload['summary']['active_total']);
        self::assertSame(600, $payload['summary']['km']);
        self::assertSame(1, $payload['summary']['km_trips']);
        self::assertSame(100.0, $payload['summary']['km_coverage']);
        self::assertSame(400.0, $payload['summary']['liters']);
        self::assertSame(2, $payload['summary']['fuel_trips']);
        self::assertSame(50.0, $payload['summary']['liters_per_100']);
        self::assertSame(400000.0, $payload['summary']['fuel_cost_ars']);
        self::assertSame(150000.0, $payload['summary']['expenses_ars']);
        self::assertSame(1400000.0, $payload['summary']['billing_ars']);
        self::assertSame('2026-10', $payload['monthly'][0]['month']);
        self::assertSame(3, $payload['monthly'][0]['trips']);
        self::assertSame(600, $payload['monthly'][0]['km']);
        self::assertSame([1, 2], array_map(static fn (array $trip): int => $trip['id'], $payload['currentTrips']));
        self::assertSame('AA111AA · T01', $payload['currentTrips'][0]['tractor']);
        self::assertSame(['Juan Pérez'], $payload['currentTrips'][0]['choferes']);
        self::assertSame('Rosario', $payload['currentTrips'][0]['origen']);
        self::assertSame('Córdoba', $payload['currentTrips'][0]['destino']);
        self::assertSame(1, $payload['currentTrips'][0]['tramos']);
        self::assertSame(2, $payload['currentTrips'][0]['paradas']);
        self::assertSame('Argentina', $payload['currentTrips'][0]['sucursal']);
        self::assertSame([1, 2], $readModel->detailIds);
        self::assertSame([1, 1, 1, 1], array_map(static fn (array $item): int => $item['value'], $payload['statusBreakdown']));
        self::assertTrue($payload['canOperate']);
        self::assertSame(base_url('viajes/operativos/dashboard'), $payload['links']['index']);
    }

    public function testHidesEconomicsForRolesWithoutTheEconomicsPermission(): void
    {
        $payload = (new GetOperationalTripsDashboard(new DashboardReadModelFake()))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10, 20]),
            new OperationalTripFilters(from: '2026-10-01', to: '2026-10-31', branchId: 10),
        );

        self::assertFalse($payload['canSeeEconomics']);
        self::assertNull($payload['summary']['billing_ars']);
        self::assertNull($payload['summary']['fuel_cost_ars']);
        self::assertNull($payload['summary']['expenses_ars']);
        self::assertNull($payload['monthly'][0]['billing_ars']);
        self::assertSame(1, $payload['summary']['en_curso']);
        self::assertSame(600, $payload['summary']['km']);
    }

    public function testKeepsCurrentTripsEvenWhenThePeriodExcludesTheirDate(): void
    {
        $payload = (new GetOperationalTripsDashboard(new DashboardReadModelFake()))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            new OperationalTripFilters(from: '2026-12-01', to: '2026-12-31'),
        );

        self::assertSame(0, $payload['summary']['total']);
        self::assertSame(0, $payload['summary']['finalizados']);
        self::assertSame(2, $payload['summary']['active_total']);
        self::assertCount(2, $payload['currentTrips']);
    }

    public function testDoesNotInventConsumptionWithoutReliableKilometres(): void
    {
        $readModel = new DashboardReadModelFake();
        $readModel->rows = [[
            'id' => 9, 'numero' => 90, 'estado' => 'FINALIZADO', 'sucursal_id' => 10,
            'fecha_salida_planificada' => '2026-10-10 08:00:00', 'fecha_regreso_planificada' => '2026-10-11 18:00:00',
            'fecha_salida_real' => '2026-10-10 08:00:00', 'fecha_regreso_real' => '2026-10-11 18:00:00',
            'km_salida' => null, 'km_regreso' => null, 'litros' => 300.0,
            'combustible_ars' => 300000.0, 'gastos_ars' => 0.0, 'flete_facturacion_ars' => 500000.0,
        ]];

        $payload = (new GetOperationalTripsDashboard($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            new OperationalTripFilters(),
        );

        self::assertNull($payload['summary']['liters_per_100']);
        self::assertSame(0, $payload['summary']['km']);
    }

    public function testReturnsEmptyPayloadWhenActorHasNoBranches(): void
    {
        $readModel = new DashboardReadModelFake();
        $payload = (new GetOperationalTripsDashboard($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], []),
            new OperationalTripFilters(),
        );

        self::assertSame([], $payload['currentTrips']);
        self::assertSame([], $payload['monthly']);
        self::assertSame(0, $payload['summary']['total']);
        self::assertSame([], $readModel->tripsQuery);
    }

    public function testRejectsActorWithoutReadPermission(): void
    {
        $this->expectException(DomainException::class);

        (new GetOperationalTripsDashboard(new DashboardReadModelFake()))->execute(
            new ActorContext(1, 5, false, false, [], [], [10]),
            new OperationalTripFilters(),
        );
    }
}

final class DashboardReadModelFake implements OperationalTripDashboardReadModel
{
    public array $tripsQuery = [];

    public array $detailIds = [];

    /** @var list<array<string,mixed>> */
    public array $rows = [
        [
            'id' => 1, 'numero' => 11, 'estado' => 'EN_CURSO', 'sucursal_id' => 10,
            'fecha_salida_planificada' => '2026-10-01 08:00:00', 'fecha_regreso_planificada' => '2026-10-03 18:00:00',
            'fecha_salida_real' => '2026-10-01 08:00:00', 'fecha_regreso_real' => null,
            'km_salida' => 1000, 'km_regreso' => null, 'litros' => 100.0,
            'combustible_ars' => 100000.0, 'gastos_ars' => 50000.0, 'flete_facturacion_ars' => 500000.0,
        ],
        [
            'id' => 2, 'numero' => 12, 'estado' => 'PLANIFICADO', 'sucursal_id' => 10,
            'fecha_salida_planificada' => '2026-11-05 08:00:00', 'fecha_regreso_planificada' => '2026-11-06 18:00:00',
            'fecha_salida_real' => null, 'fecha_regreso_real' => null,
            'km_salida' => null, 'km_regreso' => null, 'litros' => 0.0,
            'combustible_ars' => 0.0, 'gastos_ars' => 0.0, 'flete_facturacion_ars' => 0.0,
        ],
        [
            'id' => 3, 'numero' => 13, 'estado' => 'FINALIZADO', 'sucursal_id' => 10,
            'fecha_salida_planificada' => '2026-10-10 08:00:00', 'fecha_regreso_planificada' => '2026-10-11 18:00:00',
            'fecha_salida_real' => '2026-10-10 08:00:00', 'fecha_regreso_real' => '2026-10-11 18:00:00',
            'km_salida' => 1000, 'km_regreso' => 1600, 'litros' => 300.0,
            'combustible_ars' => 300000.0, 'gastos_ars' => 100000.0, 'flete_facturacion_ars' => 900000.0,
        ],
        [
            'id' => 4, 'numero' => 14, 'estado' => 'CANCELADO', 'sucursal_id' => 10,
            'fecha_salida_planificada' => '2026-10-15 08:00:00', 'fecha_regreso_planificada' => '2026-10-16 18:00:00',
            'fecha_salida_real' => null, 'fecha_regreso_real' => null,
            'km_salida' => null, 'km_regreso' => null, 'litros' => 0.0,
            'combustible_ars' => 0.0, 'gastos_ars' => 0.0, 'flete_facturacion_ars' => 0.0,
        ],
        [
            'id' => 5, 'numero' => 15, 'estado' => 'FINALIZADO', 'sucursal_id' => 10,
            'fecha_salida_planificada' => '2026-09-20 08:00:00', 'fecha_regreso_planificada' => '2026-09-21 18:00:00',
            'fecha_salida_real' => '2026-09-20 08:00:00', 'fecha_regreso_real' => '2026-09-21 18:00:00',
            'km_salida' => 5000, 'km_regreso' => 5400, 'litros' => 100.0,
            'combustible_ars' => 100000.0, 'gastos_ars' => 0.0, 'flete_facturacion_ars' => 200000.0,
        ],
    ];

    public function branches(int $companyId, ?array $branchIds): array
    {
        return [
            ['id' => 10, 'code' => 'AR', 'name' => 'Argentina'],
            ['id' => 20, 'code' => 'BR', 'name' => 'Brasil'],
        ];
    }

    public function trips(int $companyId, ?array $branchIds, ?int $branchId): array
    {
        $this->tripsQuery = [$companyId, $branchIds, $branchId];

        return $this->rows;
    }

    public function details(int $companyId, array $tripIds): array
    {
        $this->detailIds = $tripIds;

        return [
            1 => [
                'tractor' => 'AA111AA · T01',
                'choferes' => ['Juan Pérez'],
                'origen' => 'Rosario',
                'destino' => 'Córdoba',
                'paradas' => 2,
                'tramos' => 1,
            ],
            2 => [
                'tractor' => 'AA222AA · T02',
                'choferes' => ['Ana Gómez'],
                'origen' => 'Córdoba',
                'destino' => 'Mendoza',
                'paradas' => 3,
                'tramos' => 2,
            ],
        ];
    }
}
