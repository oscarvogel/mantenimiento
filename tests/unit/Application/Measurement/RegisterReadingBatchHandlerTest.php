<?php

declare(strict_types=1);

use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\ReadingPreventiveUnitOfWork;
use App\Application\MaintenanceCircuit\RegisterReadingAndReevaluate;
use App\Application\Measurement\Port\ReadingRepository;
use App\Application\Measurement\Port\UnitOfWork;
use App\Application\Measurement\RegisterReadingBatchHandler;
use App\Application\Measurement\RegisterReadingBatchItem;
use App\Application\Measurement\RegisterReadingHandler;
use App\Application\PreventiveMaintenance\EquipmentForPlan;
use App\Application\PreventiveMaintenance\MaterializarAvisoVencido;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Application\PreventiveMaintenance\ReevaluateEquipmentAfterReading;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use App\Domain\Measurement\EquipmentReading;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class RegisterReadingBatchHandlerTest extends TestCase
{
    public function testProcessesRowsIndependentlyAndDelegatesToExistingReadingRules(): void
    {
        $equipment = new BatchEquipmentRepositoryFake4b([
            10 => $this->equipment(10, 1_000),
            11 => $this->equipment(11, 2_000),
        ]);
        $readings = new BatchReadingRepositoryFake4b();
        $register = new RegisterReadingHandler($equipment, $readings, new BatchUnitOfWorkFake4b());
        $reevaluate = new ReevaluateEquipmentAfterReading(
            new BatchPlanRepositoryFake4b(),
            new BatchPreventiveAssetsFake4b(),
            new BatchClockFake4b(),
            new EvaluadorVencimiento(),
            new MaterializarAvisoVencido(new BatchNoticeRepositoryFake4b()),
        );
        $handler = new RegisterReadingBatchHandler(new RegisterReadingAndReevaluate(
            $register,
            $reevaluate,
            new BatchOuterUnitOfWorkFake4b(),
            new BatchMeasurementClockFake4b(),
        ));

        $result = $handler->execute($this->actor(), [
            new RegisterReadingBatchItem(1, 10, new DateTimeImmutable('2026-08-12 09:00:00'), 1_100, null),
            new RegisterReadingBatchItem(2, 11, new DateTimeImmutable('2026-08-12 09:00:00'), 1_900, null),
        ]);

        self::assertSame(1, $result->successful());
        self::assertSame(1, $result->failed());
        self::assertTrue($result->rows[0]['success']);
        self::assertSame(1_100, $result->rows[0]['currentKilometers']);
        self::assertTrue($result->rows[0]['submittedKilometers']);
        self::assertFalse($result->rows[0]['submittedHours']);
        self::assertFalse($result->rows[1]['success']);
        self::assertCount(1, $readings->items);
        self::assertSame('CARGA_RAPIDA', $readings->items[0]->origin());
    }

    public function testRollsBackTheWholeRowWhenNoticeMaterializationFails(): void
    {
        $equipment = new BatchEquipmentRepositoryFake4b([10 => $this->equipment(10, 100_000)]);
        $readings = new BatchReadingRepositoryFake4b();
        $plan = PlanMantenimiento::reconstituir(
            12, 5, 10, 3, 10_000, null, null, 1_000, null, null,
            90_000, null, null, 100_000, null, null, 'MEDIA', true, null,
        );
        $outer = new BatchOuterUnitOfWorkFake4b();
        $noticeRepository = new BatchNoticeRepositoryFake4b();
        $noticeRepository->fail = true;
        $coordinator = new RegisterReadingAndReevaluate(
            new RegisterReadingHandler($equipment, $readings, new BatchUnitOfWorkFake4b()),
            new ReevaluateEquipmentAfterReading(
                new BatchPlanRepositoryFake4b([$plan]),
                new BatchPreventiveAssetsFake4b(new UsoActual(110_000, null)),
                new BatchClockFake4b(),
                new EvaluadorVencimiento(),
                new MaterializarAvisoVencido($noticeRepository),
            ),
            $outer,
            new BatchMeasurementClockFake4b(),
        );

        $result = (new RegisterReadingBatchHandler($coordinator))->execute($this->actor(), [
            new RegisterReadingBatchItem(1, 10, new DateTimeImmutable('2026-08-12 09:00:00'), 110_000, null),
        ]);

        self::assertFalse($result->rows[0]['success']);
        self::assertSame(1, $outer->rollbacks);
    }

    public function testRejectsFutureReadingDateBeforePersisting(): void
    {
        $readings = new BatchReadingRepositoryFake4b();
        $coordinator = new RegisterReadingAndReevaluate(
            new RegisterReadingHandler(
                new BatchEquipmentRepositoryFake4b([10 => $this->equipment(10, 1_000)]),
                $readings,
                new BatchUnitOfWorkFake4b(),
            ),
            new ReevaluateEquipmentAfterReading(
                new BatchPlanRepositoryFake4b(), new BatchPreventiveAssetsFake4b(), new BatchClockFake4b(),
                new EvaluadorVencimiento(), new MaterializarAvisoVencido(new BatchNoticeRepositoryFake4b()),
            ),
            new BatchOuterUnitOfWorkFake4b(),
            new BatchMeasurementClockFake4b(),
        );

        $result = (new RegisterReadingBatchHandler($coordinator))->execute($this->actor(), [
            new RegisterReadingBatchItem(1, 10, new DateTimeImmutable('2026-08-12 11:00:00'), 1_100, null),
        ]);

        self::assertFalse($result->rows[0]['success']);
        self::assertCount(0, $readings->items);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Operador'], ['lecturas.cargar'], [7]);
    }

    private function equipment(int $id, int $kilometers): Equipment
    {
        return Equipment::reconstitute(
            $id, 5, 7, new EquipmentType(3, 'Camión', true, false), 'EQ-' . $id,
            null, Equipment::ACTIVE, new DateTimeImmutable('2026-08-01'), null, null, $kilometers, null,
        );
    }
}

final class BatchEquipmentRepositoryFake4b implements EquipmentRepository
{
    /** @param array<int,Equipment> $equipment */
    public function __construct(private array $equipment) {}
    public function codeExists(int $companyId, string $code): bool { return false; }
    public function add(Equipment $equipment, int $actorUserId): int { return 1; }
    public function findForUpdate(int $equipmentId, int $companyId): ?Equipment { return $this->equipment[$equipmentId] ?? null; }
    public function updateCurrentUsage(Equipment $equipment, int $actorUserId): void {}
}

final class BatchReadingRepositoryFake4b implements ReadingRepository
{
    /** @var list<EquipmentReading> */ public array $items = [];
    public function append(EquipmentReading $reading): int { $this->items[] = $reading; return count($this->items); }
}

final class BatchUnitOfWorkFake4b implements UnitOfWork
{
    public function transactional(callable $operation): mixed { return $operation(); }
}

final class BatchPlanRepositoryFake4b implements PlanMantenimientoRepository
{
    /** @param list<PlanMantenimiento> $plans */ public function __construct(private array $plans = []) {}
    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento { return null; }
    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool { return false; }
    public function listActiveScoped(int $companyId, ?array $branchIds): array { return $this->plans; }
    public function save(PlanMantenimiento $plan, int $actorUserId): int { return 1; }
}

final class BatchPreventiveAssetsFake4b implements PreventiveAssetGateway
{
    public function __construct(private ?UsoActual $usage = null) {}
    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?EquipmentForPlan
    {
        return new EquipmentForPlan($equipmentId, $companyId, 7, true, true, false, $this->usage ?? new UsoActual(null, null));
    }
}

final class BatchClockFake4b implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-08-12 09:00:00'); }
}

final class BatchOuterUnitOfWorkFake4b implements ReadingPreventiveUnitOfWork
{
    public int $rollbacks = 0;
    public function transactional(callable $operation): mixed
    {
        try { return $operation(); } catch (Throwable $exception) { ++$this->rollbacks; throw $exception; }
    }
}

final class BatchMeasurementClockFake4b implements \App\Application\Measurement\Port\Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-08-12 10:00:00'); }
}

final class BatchNoticeRepositoryFake4b implements MaintenanceNoticeRepository
{
    public bool $fail = false;
    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?AvisoPlan { return null; }
    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?AvisoPlan { return null; }
    public function save(AvisoPlan $notice, ?int $actorUserId): int { if ($this->fail) { throw new RuntimeException('notice failed'); } return 1; }
}
