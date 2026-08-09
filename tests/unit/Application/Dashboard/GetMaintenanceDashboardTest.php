<?php

declare(strict_types=1);

use App\Application\Dashboard\GetMaintenanceDashboard;
use App\Application\Dashboard\Port\DashboardDuePlans;
use App\Application\Dashboard\Port\DashboardOverview;
use App\Application\Dashboard\Port\DashboardClock;
use App\Application\Identity\ActorContext;
use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluacionPlan;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use PHPUnit\Framework\TestCase;

final class GetMaintenanceDashboardTest extends TestCase
{
    public function testBuildsScopedOperationalMetricsAndPrioritizesOverdueMaintenance(): void
    {
        $overview = new DashboardOverviewFake();
        $due = new DashboardDuePlansFake([
            $this->dueResult(10, 100, EstadoPlan::PROXIMO, [CriterioPlan::KILOMETRAJE]),
            $this->dueResult(11, 101, EstadoPlan::VENCIDO, [CriterioPlan::KILOMETRAJE]),
        ]);
        $result = (new GetMaintenanceDashboard($overview, $due, new DashboardClockFake()))->execute($this->actor([
            'equipos.ver', 'planes.ver', 'ordenes.ver',
        ]));

        self::assertSame(2, $result['metrics']['equipmentTotal']);
        self::assertSame(1, $result['metrics']['equipmentActive']);
        self::assertSame(1, $result['metrics']['maintenanceDueSoon']);
        self::assertSame(1, $result['metrics']['maintenanceOverdue']);
        self::assertSame(1, $result['metrics']['openOrders']);
        self::assertSame('VENCIDO', $result['upcomingMaintenance'][0]['status']);
        self::assertSame('Vencido por 200 km', $result['upcomingMaintenance'][0]['remaining']);
    }

    public function testDoesNotExposeMaintenanceOrOrdersWithoutTheirPermissions(): void
    {
        $due = new DashboardDuePlansFake([$this->dueResult(10, 100, EstadoPlan::VENCIDO, [CriterioPlan::KILOMETRAJE])]);
        $result = (new GetMaintenanceDashboard(new DashboardOverviewFake(), $due, new DashboardClockFake()))->execute(
            $this->actor(['equipos.ver']),
        );

        self::assertFalse($due->called);
        self::assertSame(0, $result['metrics']['maintenanceOverdue']);
        self::assertSame(0, $result['metrics']['openOrders']);
        self::assertSame([], $result['upcomingMaintenance']);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(7, 5, false, true, ['Administrador'], $permissions, []);
    }

    /** @param list<CriterioPlan> $criteria @return array{plan:PlanMantenimiento,evaluation:EvaluacionPlan} */
    private function dueResult(int $planId, int $equipmentId, EstadoPlan $state, array $criteria): array
    {
        $plan = PlanMantenimiento::reconstituir(
            $planId, 5, $equipmentId, 1,
            1000, null, null,
            100, null, null,
            1000, null, null,
            2000, null, null,
            'MEDIA', true, null,
        );

        return [
            'plan' => $plan,
            'evaluation' => new EvaluacionPlan(
                $state,
                $state === EstadoPlan::VENCIDO ? $criteria : [],
                $state === EstadoPlan::PROXIMO ? $criteria : [],
                [],
            ),
        ];
    }
}

final class DashboardOverviewFake implements DashboardOverview
{
    public function fetch(ActorContext $actor): array
    {
        return [
            'company' => ['id' => 5, 'razon_social' => 'Transportes Demo', 'nombre_fantasia' => 'Transportes Demo'],
            'branches' => [['id' => 9, 'codigo' => 'CENTRAL', 'nombre' => 'Casa Central']],
            'equipments' => [
                ['id' => 100, 'estado' => 'ACTIVO'],
                ['id' => 101, 'estado' => 'BAJA'],
            ],
            'orders' => [
                ['id' => 1, 'estado' => 'EN_PROCESO'],
                ['id' => 2, 'estado' => 'FINALIZADA'],
            ],
            'plans' => [
                ['id' => 10, 'equipo_codigo' => 'SCANIA-R450', 'servicio_nombre' => 'Cambio de aceite', 'sucursal_nombre' => 'Central', 'proximo_km' => 2000, 'km_actual' => 1500, 'proximas_horas' => null, 'horas_actuales' => null, 'proxima_fecha' => null],
                ['id' => 11, 'equipo_codigo' => 'VOLVO-FH', 'servicio_nombre' => 'Servicio mayor', 'sucursal_nombre' => 'Central', 'proximo_km' => 2000, 'km_actual' => 2200, 'proximas_horas' => null, 'horas_actuales' => null, 'proxima_fecha' => null],
            ],
        ];
    }
}

final class DashboardDuePlansFake implements DashboardDuePlans
{
    public bool $called = false;

    public function __construct(private readonly array $results)
    {
    }

    public function fetch(ActorContext $actor, int $companyId): array
    {
        $this->called = true;

        return $this->results;
    }
}

final class DashboardClockFake implements DashboardClock
{
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-08');
    }
}
