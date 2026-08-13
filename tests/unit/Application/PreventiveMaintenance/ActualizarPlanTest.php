<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ActualizarPlan;
use App\Application\PreventiveMaintenance\ActualizarPlanCommand;
use App\Application\PreventiveMaintenance\EquipmentForPlan;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class ActualizarPlanTest extends TestCase
{
    private function existingPlan(): PlanMantenimiento
    {
        return PlanMantenimiento::reconstituir(
            12, 5, 20, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
            100_000, null, null,
            'MEDIA', true, 'Original', 7, 21,
        );
    }

    public function testRejectsActorWithoutPlansEditPermission(): void
    {
        $actor = new ActorContext(9, 5, false, true, ['Consulta'], ['planes.ver'], []);
        $useCase = new ActualizarPlan(new ReconfigurablePlanRepository(), new ReconfigurableAssetGateway());

        $this->expectException(DomainException::class);
        $useCase->execute($this->command($actor));
    }

    public function testRejectsSuperAdminWithoutCompanyScope(): void
    {
        $actor = new ActorContext(1, null, true, false, ['Superadministrador'], ['planes.editar'], []);
        $useCase = new ActualizarPlan(new ReconfigurablePlanRepository(), new ReconfigurableAssetGateway());

        $this->expectException(DomainException::class);
        $useCase->execute($this->command($actor));
    }

    public function testRejectsPlanOutsideAuthorizedBranches(): void
    {
        $actor = new ActorContext(9, 5, false, false, ['Responsable'], ['planes.editar'], [99]);
        $useCase = new ActualizarPlan(new ReconfigurablePlanRepository(null), new ReconfigurableAssetGateway());

        $this->expectException(DomainException::class);
        $useCase->execute($this->command($actor));
    }

    public function testRejectsIntervalForCriterionEquipmentDoesNotTrack(): void
    {
        $actor = new ActorContext(9, 5, false, true, ['Responsable'], ['planes.editar'], []);
        $asset = new ReconfigurableAssetGateway(new EquipmentForPlan(
            20, 5, 7, true, true, false, new UsoActual(90_000, null),
        ));
        $useCase = new ActualizarPlan(new ReconfigurablePlanRepository(), $asset);

        $this->expectException(DomainException::class);
        $useCase->execute($this->command($actor, intervalHoursTenths: 500));
    }

    public function testSavesReconfiguredPlanPreservingIdentityAndUpdater(): void
    {
        $actor = new ActorContext(9, 5, false, true, ['Responsable'], ['planes.editar'], []);
        $plans = new ReconfigurablePlanRepository($this->existingPlan());
        $useCase = new ActualizarPlan($plans, new ReconfigurableAssetGateway());

        $id = $useCase->execute($this->command(
            $actor,
            intervalKm: 20_000,
            baseKm: 150_000,
            priority: 'ALTA',
            notes: 'Nuevo ciclo',
        ));

        self::assertSame(12, $id);
        self::assertNotNull($plans->saved);
        self::assertSame(150_000, $plans->saved->baseKm());
        self::assertSame(170_000, $plans->saved->proximoKm());
        self::assertSame('ALTA', $plans->saved->prioridad());
        self::assertSame('Nuevo ciclo', $plans->saved->observaciones());
        self::assertSame(7, $plans->saved->origenPlantillaId());
        self::assertSame(21, $plans->saved->origenPlantillaItemId());
        self::assertSame(9, $plans->lastActorUserId);
    }

    private function command(
        ActorContext $actor,
        ?int $intervalKm = 10_000,
        ?int $intervalHoursTenths = null,
        ?int $baseKm = 95_000,
        string $priority = 'MEDIA',
        ?string $notes = 'Ajuste',
    ): ActualizarPlanCommand {
        return new ActualizarPlanCommand(
            $actor,
            5,
            12,
            $intervalKm,
            $intervalHoursTenths,
            null,
            1_000,
            null,
            null,
            baseKm: $baseKm,
            baseHoursTenths: null,
            baseDate: null,
            priority: $priority,
            notes: $notes,
        );
    }
}

final class ReconfigurablePlanRepository implements PlanMantenimientoRepository
{
    public ?PlanMantenimiento $found = null;
    public ?PlanMantenimiento $saved = null;
    public ?int $lastActorUserId = null;

    public function __construct(?PlanMantenimiento $found = null)
    {
        $this->found = $found;
    }

    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento
    {
        return $this->found;
    }

    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool
    {
        return false;
    }

    public function listActiveScoped(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function save(PlanMantenimiento $plan, int $actorUserId): int
    {
        $this->saved = $plan;
        $this->lastActorUserId = $actorUserId;

        return (int) $plan->id();
    }
}

final readonly class ReconfigurableAssetGateway implements PreventiveAssetGateway
{
    public function __construct(private ?EquipmentForPlan $equipment = null)
    {
    }

    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?EquipmentForPlan
    {
        return $this->equipment ?? new EquipmentForPlan(
            20, 5, 7, true, true, true, new UsoActual(90_000, 1_000),
        );
    }
}