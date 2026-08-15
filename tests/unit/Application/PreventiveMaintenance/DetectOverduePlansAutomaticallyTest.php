<?php

declare(strict_types=1);

use App\Application\PreventiveMaintenance\ConsultarVencimientos;
use App\Application\PreventiveMaintenance\DetectOverduePlansAutomatically;
use App\Application\PreventiveMaintenance\EquipmentForPlan;
use App\Application\PreventiveMaintenance\MaterializarAvisoVencido;
use App\Application\PreventiveMaintenance\Port\ActiveCompanyCatalog;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class DetectOverduePlansAutomaticallyTest extends TestCase
{
    public function testMaterializesDateOverduePlanWithoutDuplicateOnSecondRun(): void
    {
        $plans = new AutomaticFakePlanRepository([$this->annualPlan(5, 20)]);
        $notices = new AutomaticFakeNoticeRepository();
        $useCase = $this->useCase($plans, $notices, [5]);

        $first = $useCase->execute();
        $second = $useCase->execute();

        self::assertSame(1, $first['companies']);
        self::assertSame(1, $first['evaluated']);
        self::assertSame(1, $first['overdue']);
        self::assertCount(1, $first['notices']);
        self::assertSame(1, $notices->saveCount);
        self::assertSame($first['notices'], $second['notices']);
        self::assertSame(1, $notices->saveCount);
    }

    public function testProcessesOnlyActiveCompaniesAndActivePlans(): void
    {
        $plans = new AutomaticFakePlanRepository([$this->inactiveAnnualPlan(5, 20)]);
        $notices = new AutomaticFakeNoticeRepository();
        $useCase = $this->useCase($plans, $notices, [5]);

        $result = $useCase->execute();

        self::assertSame([5], $plans->requestedCompanies);
        self::assertSame(0, $result['evaluated']);
        self::assertSame(0, $notices->saveCount);
    }

    public function testActorScopedQueryKeepsBranchScope(): void
    {
        $plans = new AutomaticFakePlanRepository([$this->annualPlan(5, 20)]);
        $assets = new AutomaticFakeAssetGateway(new EquipmentForPlan(20, 5, 7, true, false, false, new UsoActual(null, null)));
        $query = new ConsultarVencimientos($plans, $assets, new AutomaticFixedClock(), new EvaluadorVencimiento());
        $actor = new App\Application\Identity\ActorContext(9, 5, false, false, ['Tecnico'], ['planes.ver'], [8]);

        self::assertSame([], $query->execute($actor, 5));
        self::assertSame([5, [8]], $plans->lastScope);
    }

    private function useCase(AutomaticFakePlanRepository $plans, AutomaticFakeNoticeRepository $notices, array $companies): DetectOverduePlansAutomatically
    {
        $query = new ConsultarVencimientos(
            $plans,
            new AutomaticFakeAssetGateway(new EquipmentForPlan(20, 5, 7, true, false, false, new UsoActual(null, null))),
            new AutomaticFixedClock(),
            new EvaluadorVencimiento(),
        );

        return new DetectOverduePlansAutomatically(
            new AutomaticFakeCompanyCatalog($companies),
            $query,
            new MaterializarAvisoVencido($notices),
            new AutomaticFixedClock(),
        );
    }

    private function annualPlan(int $companyId, int $equipmentId): PlanMantenimiento
    {
        return PlanMantenimiento::reconstituir(
            12, $companyId, $equipmentId, 3,
            null, null, 365,
            null, null, 30,
            null, null, new DateTimeImmutable('2024-01-01'),
            null, null, new DateTimeImmutable('2024-12-31'),
            'MEDIA', true, null,
        );
    }

    private function inactiveAnnualPlan(int $companyId, int $equipmentId): PlanMantenimiento
    {
        return PlanMantenimiento::reconstituir(
            13, $companyId, $equipmentId, 3,
            null, null, 365,
            null, null, 30,
            null, null, new DateTimeImmutable('2024-01-01'),
            null, null, new DateTimeImmutable('2024-12-31'),
            'MEDIA', false, null,
        );
    }
}

final readonly class AutomaticFakeCompanyCatalog implements ActiveCompanyCatalog
{
    public function __construct(private array $companies) {}
    public function listActiveCompanyIds(): array { return $this->companies; }
}

final class AutomaticFakePlanRepository implements PlanMantenimientoRepository
{
    public array $requestedCompanies = [];
    public ?array $lastScope = null;

    public function __construct(private array $plans) {}
    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento { return null; }
    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool { return false; }
    public function listActiveScoped(int $companyId, ?array $branchIds): array
    {
        $this->requestedCompanies[] = $companyId;
        $this->lastScope = [$companyId, $branchIds];
        return array_values(array_filter($this->plans, static fn (PlanMantenimiento $plan): bool => $plan->empresaId() === $companyId && $plan->activo()));
    }
    public function save(PlanMantenimiento $plan, int $actorUserId): int { return (int) $plan->id(); }
}

final readonly class AutomaticFakeAssetGateway implements PreventiveAssetGateway
{
    public function __construct(private EquipmentForPlan $equipment) {}
    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?EquipmentForPlan
    {
        if ($branchIds !== null && ! in_array($this->equipment->branchId, $branchIds, true)) {
            return null;
        }
        return $this->equipment;
    }
}

final readonly class AutomaticFixedClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-08-15 07:00:00'); }
}

final class AutomaticFakeNoticeRepository implements MaintenanceNoticeRepository
{
    /** @var array<string,AvisoPlan> */
    private array $byCycle = [];
    public int $saveCount = 0;

    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?AvisoPlan { return $this->byCycle[$cycleKey] ?? null; }
    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?AvisoPlan { return null; }
    public function save(AvisoPlan $notice, ?int $actorUserId): int
    {
        ++$this->saveCount;
        $id = 30 + $this->saveCount;
        $this->byCycle[$notice->claveCiclo()] = AvisoPlan::reconstituir(
            $id, $notice->empresaId(), $notice->planId(), $notice->equipoId(), $notice->claveCiclo(),
            $notice->criteriosDisparadores(), $notice->fechaDeteccion(), $notice->estadoGestion(),
            $notice->fechaResolucion(), $notice->motivoResolucion(),
        );
        return $id;
    }
}
