<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GetOperationalTrip;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\OperationalTripReadModel;
use PHPUnit\Framework\TestCase;

final class GetOperationalTripTest extends TestCase
{
    public function testLoadsDetailWithinActorScopeAndEnablesActionsForPlannedTrip(): void
    {
        $readModel = new GetOperationalTripReadModelFake('PLANIFICADO');
        $detail = (new GetOperationalTrip($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar'], [10]),
            55,
        );

        self::assertSame([5, [10], 55], $readModel->query);
        self::assertSame(42, $detail['trip']['numero']);
        self::assertTrue($detail['canStart']);
        self::assertTrue($detail['canCancel']);
        self::assertSame(base_url('viajes/operativos/55'), $detail['links']['detail']);
    }

    public function testHidesActionsWhenTripAlreadyStarted(): void
    {
        $readModel = new GetOperationalTripReadModelFake('EN_CURSO');
        $detail = (new GetOperationalTrip($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar'], [10]),
            55,
        );

        self::assertFalse($detail['canStart']);
        self::assertFalse($detail['canCancel']);
        self::assertTrue($detail['canClose']);
    }

    public function testComputesCommissionAndMarginWithAssignedAndUnassignedCosts(): void
    {
        $detail = $this->detail([
            'assignments' => [['rol' => 'CHOFER', 'porcentaje_liquidacion' => 14.0]],
            'legs' => [[
                'id' => 1,
                'orden' => 1,
                'origen' => 'Rosario',
                'destino' => 'Córdoba',
                'flete_facturacion_ars' => 1_000_000.0,
                'flete_liquidacion_ars' => 800_000.0,
            ]],
            'fuel' => [['importe_ars' => 200_000.0, 'tramo_id' => 1, 'litros' => 300.0, 'kilometraje' => null]],
            'expenses' => [
                ['importe_ars' => 50_000.0, 'tramo_id' => 1, 'pagado_de_caja' => false],
                ['importe_ars' => 10_000.0, 'tramo_id' => null, 'pagado_de_caja' => false],
            ],
        ]);

        self::assertSame(1_000_000.0, $detail['totals']['invoice_ars']);
        self::assertSame(112_000.0, $detail['totals']['commission_ars']);
        self::assertSame(250_000.0, $detail['totals']['assigned_costs_ars']);
        self::assertSame(10_000.0, $detail['totals']['unassigned_costs_ars']);
        self::assertSame(628_000.0, $detail['totals']['margin_ars']);
        self::assertSame(62.8, $detail['totals']['margin_pct']);
        self::assertSame(112_000.0, $detail['legs'][0]['commission_ars']);
        self::assertSame(638_000.0, $detail['legs'][0]['margin_ars']);
    }

    public function testDoesNotInventCommissionWhenThereAreSeveralDrivers(): void
    {
        $detail = $this->detail([
            'assignments' => [
                ['rol' => 'CHOFER', 'porcentaje_liquidacion' => 14.0],
                ['rol' => 'CHOFER', 'porcentaje_liquidacion' => 10.0],
            ],
            'legs' => [[
                'id' => 1,
                'orden' => 1,
                'origen' => 'Rosario',
                'destino' => 'Córdoba',
                'flete_facturacion_ars' => 1_000_000.0,
                'flete_liquidacion_ars' => 800_000.0,
            ]],
        ]);

        self::assertTrue($detail['totals']['multiple_drivers']);
        self::assertNull($detail['totals']['commission_ars']);
        self::assertNull($detail['totals']['margin_ars']);
        self::assertNull($detail['legs'][0]['commission_ars']);
    }

    public function testShowsNoMarginWithoutLegs(): void
    {
        $detail = $this->detail([]);

        self::assertFalse($detail['totals']['has_legs']);
        self::assertNull($detail['totals']['margin_ars']);
        self::assertSame(0.0, $detail['totals']['invoice_ars']);
    }

    public function testRejectsActorWithoutReadPermission(): void
    {
        $this->expectException(DomainException::class);

        (new GetOperationalTrip(new GetOperationalTripReadModelFake('PLANIFICADO')))->execute(
            new ActorContext(1, 5, false, false, [], [], [10]),
            55,
        );
    }

    public function testTreatsTripOutsideScopeAsNotFound(): void
    {
        $readModel = new GetOperationalTripReadModelFake(null);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no existe');

        (new GetOperationalTrip($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            55,
        );
    }

    /** @param array<string,mixed> $data */
    private function detail(array $data): array
    {
        return (new GetOperationalTrip(new GetOperationalTripReadModelFake('EN_CURSO', $data)))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar'], [10]),
            55,
        );
    }
}

final class GetOperationalTripReadModelFake implements OperationalTripReadModel
{
    public array $query = [];

    /** @param array<string,mixed> $data */
    public function __construct(private ?string $status, private array $data = [])
    {
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function list(int $companyId, ?array $branchIds, OperationalTripFilters $filters, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function find(int $companyId, ?array $branchIds, int $tripId): ?array
    {
        $this->query = [$companyId, $branchIds, $tripId];
        if ($this->status === null) {
            return null;
        }

        return array_replace([
            'trip' => ['id' => $tripId, 'numero' => 42, 'estado' => $this->status],
            'assignments' => [],
            'stops' => [],
            'legs' => [],
            'fuel' => [],
            'expenses' => [],
            'cashMovements' => [],
        ], $this->data);
    }
}
