<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\EquipmentForPlan;
use App\Application\PreventiveMaintenance\MaterializeSuggestedPlans;
use App\Application\PreventiveMaintenance\MaterializeSuggestedPlansCommand;
use App\Application\PreventiveMaintenance\MaterializarAvisoVencido;
use App\Application\PreventiveMaintenance\PlanTemplateSelection;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Application\PreventiveMaintenance\Port\PreventiveTemplateGateway;
use App\Application\PreventiveMaintenance\Port\PreventiveUnitOfWork;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\PlantillaPreventiva;
use App\Domain\PreventiveMaintenance\ResolverPlantillasCompatibles;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class SmartPlanAssignmentTest extends TestCase
{
    public function testUsedTruckKeepsCurrentReadingSeparateFromHistoricalBase(): void
    {
        [$useCase, $plans, $notices, $unit] = $this->fixture(
            new UsoActual(185_000, null),
            new PlantillaPreventiva(11, 4, 'Iveco Tector', 9, 'Aceite', 3, 'IVECO', 'TECTOR', 20_000, null, null, 2_000, null, null, 'MEDIA', null),
        );

        $result = $useCase->execute(new MaterializeSuggestedPlansCommand(
            $this->actor(), 20, [new PlanTemplateSelection(11, baseKm: 170_000)],
        ));

        self::assertSame([101], $result->planIds);
        self::assertSame([], $result->noticeIds);
        self::assertSame(170_000, $plans->saved[0]->baseKm());
        self::assertSame(190_000, $plans->saved[0]->proximoKm());
        self::assertSame(4, $plans->saved[0]->origenPlantillaId());
        self::assertSame(11, $plans->saved[0]->origenPlantillaItemId());
        self::assertSame(1, $unit->transactions);
        self::assertSame(0, $notices->saveCount);
    }

    public function testOverdueAnnualPlanCreatesOneNoticeAndNoWorkOrder(): void
    {
        [$useCase, , $notices] = $this->fixture(
            new UsoActual(null, null),
            new PlantillaPreventiva(12, 5, 'Revision anual', 10, 'Revision', 3, null, null, null, null, 365, null, null, 30, 'ALTA', null),
        );

        $result = $useCase->execute(new MaterializeSuggestedPlansCommand(
            $this->actor(), 20, [new PlanTemplateSelection(12, baseDate: new DateTimeImmutable('2024-01-01'))],
        ));

        self::assertSame([31], $result->noticeIds);
        self::assertSame(1, $notices->saveCount);
    }

    /** @return array{MaterializeSuggestedPlans,SmartFakePlanRepository,SmartFakeNoticeRepository,SmartFakeUnitOfWork} */
    private function fixture(UsoActual $usage, PlantillaPreventiva $template): array
    {
        $plans = new SmartFakePlanRepository();
        $notices = new SmartFakeNoticeRepository();
        $unit = new SmartFakeUnitOfWork();
        $assets = new SmartFakeAssetGateway(new EquipmentForPlan(20, 5, 7, true, true, false, $usage, 3, 'IVECO', 'TECTOR'));
        $useCase = new MaterializeSuggestedPlans(
            new SmartFakeTemplateGateway([$template]),
            $assets,
            $plans,
            new ResolverPlantillasCompatibles(),
            new EvaluadorVencimiento(),
            new MaterializarAvisoVencido($notices),
            $unit,
            new SmartFixedClock(),
        );

        return [$useCase, $plans, $notices, $unit];
    }

    private function actor(): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Responsable'], ['planes.editar'], [7]);
    }
}

final class SmartFakePlanRepository implements PlanMantenimientoRepository
{
    /** @var list<PlanMantenimiento> */
    public array $saved = [];

    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento { return null; }
    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool { return false; }
    public function listActiveScoped(int $companyId, ?array $branchIds): array { return []; }
    public function save(PlanMantenimiento $plan, int $actorUserId): int { $this->saved[] = $plan; return 100 + count($this->saved); }
}

final readonly class SmartFakeAssetGateway implements PreventiveAssetGateway
{
    public function __construct(private EquipmentForPlan $equipment) {}
    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?EquipmentForPlan { return $this->equipment; }
}

final readonly class SmartFakeTemplateGateway implements PreventiveTemplateGateway
{
    /** @param list<PlantillaPreventiva> $templates */
    public function __construct(private array $templates) {}
    public function listActiveCandidates(int $companyId): array { return $this->templates; }
}

final class SmartFakeUnitOfWork implements PreventiveUnitOfWork
{
    public int $transactions = 0;
    public function transactional(callable $operation): mixed { ++$this->transactions; return $operation(); }
}

final class SmartFakeNoticeRepository implements MaintenanceNoticeRepository
{
    public int $saveCount = 0;
    /** @var array<string,AvisoPlan> */
    private array $byCycle = [];
    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?AvisoPlan { return $this->byCycle[$cycleKey] ?? null; }
    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?AvisoPlan { return null; }
    public function save(AvisoPlan $notice, ?int $actorUserId): int { ++$this->saveCount; $this->byCycle[$notice->claveCiclo()] = $notice; return 31; }
}

final readonly class SmartFixedClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-08-11 12:00:00'); }
}
