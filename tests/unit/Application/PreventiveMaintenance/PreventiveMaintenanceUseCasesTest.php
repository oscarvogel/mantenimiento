<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\AsignarPlan;
use App\Application\PreventiveMaintenance\AsignarPlanCommand;
use App\Application\PreventiveMaintenance\EquipmentForPlan;
use App\Application\PreventiveMaintenance\MaterializarAvisoVencido;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class PreventiveMaintenanceUseCasesTest extends TestCase
{
    public function testAssignPlanUsesCurrentReadingAndInjectedClockAsDefaultBases(): void
    {
        $plans = new FakePlanRepository();
        $assets = new FakePreventiveAssetGateway(new EquipmentForPlan(
            20,
            5,
            7,
            true,
            true,
            true,
            new UsoActual(90_000, 1_000),
        ));
        $useCase = new AsignarPlan($plans, $assets, new FakeServiceTypeGateway(true), new FixedPreventiveClock('2026-01-15'));
        $actor = new ActorContext(9, 5, false, false, ['Responsable'], ['planes.editar'], [7]);

        $id = $useCase->execute(new AsignarPlanCommand(
            $actor,
            5,
            20,
            3,
            10_000,
            500,
            30,
            1_000,
            50,
            5,
        ));

        self::assertSame(77, $id);
        self::assertNotNull($plans->saved);
        self::assertSame(90_000, $plans->saved->baseKm());
        self::assertSame(100_000, $plans->saved->proximoKm());
        self::assertSame(1_000, $plans->saved->baseHorasDecimas());
        self::assertSame(1_500, $plans->saved->proximasHorasDecimas());
        self::assertSame('2026-01-15', $plans->saved->baseFecha()?->format('Y-m-d'));
        self::assertSame('2026-02-14', $plans->saved->proximaFecha()?->format('Y-m-d'));
    }

    public function testAssignPlanRejectsAResourceOutsideAuthorizedBranches(): void
    {
        $useCase = new AsignarPlan(
            new FakePlanRepository(),
            new FakePreventiveAssetGateway(null),
            new FakeServiceTypeGateway(true),
            new FixedPreventiveClock('2026-01-15'),
        );
        $actor = new ActorContext(9, 5, false, false, ['Responsable'], ['planes.editar'], [7]);

        $this->expectException(DomainException::class);
        $useCase->execute(new AsignarPlanCommand(
            $actor,
            5,
            999,
            3,
            10_000,
            null,
            null,
            1_000,
            null,
            null,
        ));
    }

    public function testMaterializeNoticeIsIdempotentForSamePlanCycle(): void
    {
        $plan = PlanMantenimiento::reconstituir(
            12, 5, 20, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
            100_000, null, null,
            'MEDIA', true, null,
        );
        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(100_000, null),
            new DateTimeImmutable('2026-01-15'),
        );
        $notices = new FakeNoticeRepository();
        $useCase = new MaterializarAvisoVencido($notices);

        $firstId = $useCase->execute($plan, $evaluation, new DateTimeImmutable('2026-01-15 07:00:00'));
        $notices->existing = AvisoPlan::reconstituir(
            $firstId,
            $notices->saved->empresaId(),
            $notices->saved->planId(),
            $notices->saved->equipoId(),
            $notices->saved->claveCiclo(),
            $notices->saved->criteriosDisparadores(),
            $notices->saved->fechaDeteccion(),
            $notices->saved->estadoGestion(),
            null,
            null,
        );
        $secondId = $useCase->execute($plan, $evaluation, new DateTimeImmutable('2026-01-15 08:00:00'));

        self::assertSame(31, $firstId);
        self::assertSame($firstId, $secondId);
        self::assertSame(1, $notices->saveCount);
    }
}

final class FakePlanRepository implements PlanMantenimientoRepository
{
    public ?PlanMantenimiento $saved = null;
    public bool $duplicate = false;

    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento
    {
        return null;
    }

    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool
    {
        return $this->duplicate;
    }

    public function listActiveScoped(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function save(PlanMantenimiento $plan, int $actorUserId): int
    {
        $this->saved = $plan;

        return 77;
    }
}

final readonly class FakePreventiveAssetGateway implements PreventiveAssetGateway
{
    public function __construct(private ?EquipmentForPlan $equipment)
    {
    }

    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?EquipmentForPlan
    {
        return $this->equipment;
    }
}

final readonly class FakeServiceTypeGateway implements ServiceTypeGateway
{
    public function __construct(private bool $active)
    {
    }

    public function isActive(int $serviceTypeId): bool
    {
        return $this->active;
    }
}

final readonly class FixedPreventiveClock implements Clock
{
    public function __construct(private string $date)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->date);
    }
}

final class FakeNoticeRepository implements MaintenanceNoticeRepository
{
    public ?AvisoPlan $existing = null;
    public ?AvisoPlan $saved = null;
    public int $saveCount = 0;

    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?AvisoPlan
    {
        return $this->existing;
    }

    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?AvisoPlan
    {
        return $this->existing;
    }

    public function save(AvisoPlan $notice, ?int $actorUserId): int
    {
        $this->saved = $notice;
        ++$this->saveCount;

        return 31;
    }
}
