<?php

declare(strict_types=1);

use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Identity\ActorContext;
use App\Application\Measurement\CorrectReadingCommand;
use App\Application\Measurement\CorrectReadingHandler;
use App\Application\Measurement\Port\ReadingCorrectionRepository;
use App\Application\Measurement\Port\ReadingRepository;
use App\Application\Measurement\Port\UnitOfWork;
use App\Application\Measurement\Port\WorkOrderReadingCorrectionSynchronizer;
use App\Application\Notifications\Port\NotifiableEventPublisher;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationSeverity;
use PHPUnit\Framework\TestCase;

final class CorrectReadingHandlerTest extends TestCase
{
    public function testPublishesCriticalNotificationReturnedForFinalizedWorkOrder(): void
    {
        $publisher = new CorrectionNotificationPublisherFake();
        $event = new NotifiableEvent(
            5,
            7,
            'orden.rectificada',
            NotificationSeverity::CRITICAL,
            'OT-2026-000001 rectificada después de su cierre',
            'Se modificó el kilometraje de una OT cerrada.',
            'orden_trabajo',
            '44',
            'orden_rectificada:ot:44:lectura:82',
            '/mantenimiento/equipos/10',
            new DateTimeImmutable('2026-08-08 12:00:00'),
        );
        $handler = new CorrectReadingHandler(
            new CorrectionEquipmentRepositoryFake($this->equipment()),
            new CorrectionAppendRepositoryFake(),
            new ReadingCorrectionRepositoryFake($this->reading(), UsageMeasurement::from(1400, '35.5')),
            new CorrectionUnitOfWorkFake(),
            new CorrectionWorkOrderSynchronizerFake($event),
            $publisher,
        );

        $handler->execute(
            $this->actor(['lecturas.corregir'], [9]),
            new CorrectReadingCommand(10, 81, 950, '19.5', 'Corrección de OT cerrada', new DateTimeImmutable('2026-08-08 12:00:00')),
        );

        self::assertSame($event, $publisher->published);
        self::assertSame(NotificationSeverity::CRITICAL, $publisher->published?->severity());
        self::assertSame('orden.rectificada', $publisher->published?->type());
    }

    public function testCorrectsHistoricalReadingFromPreviousBranchAndRecalculatesFullEquipmentProjection(): void
    {
        $equipment = $this->equipment(branchId: 9);
        $original = $this->reading(branchId: 7);
        $equipmentRepository = new CorrectionEquipmentRepositoryFake($equipment);
        $append = new CorrectionAppendRepositoryFake();
        $corrections = new ReadingCorrectionRepositoryFake($original, UsageMeasurement::from(1400, '35.5'));
        $unitOfWork = new CorrectionUnitOfWorkFake();
        $handler = new CorrectReadingHandler($equipmentRepository, $append, $corrections, $unitOfWork);

        $result = $handler->execute(
            $this->actor(['lecturas.corregir'], [9]),
            new CorrectReadingCommand(
                10,
                81,
                950,
                '19.5',
                'Error de transcripción',
                new DateTimeImmutable('2026-08-08 12:00:00'),
            ),
        );

        self::assertSame(82, $result->correctionReadingId);
        self::assertSame(9, $result->branchId);
        self::assertSame(1400, $result->currentKilometers);
        self::assertSame('35.5', $result->currentHours);
        self::assertSame(81, $append->appended?->correctedReadingId());
        self::assertSame(7, $append->appended?->branchId(), 'La corrección conserva la sucursal histórica.');
        self::assertTrue($original->isAnnulled());
        self::assertSame(1, $corrections->projectionRecalculations);
        self::assertSame(1, $unitOfWork->commits);
    }

    public function testRejectsReadingFromAnotherEquipmentWithoutRevealingIt(): void
    {
        $corrections = new ReadingCorrectionRepositoryFake($this->reading(equipmentId: 11));
        $unitOfWork = new CorrectionUnitOfWorkFake();
        $handler = new CorrectReadingHandler(
            new CorrectionEquipmentRepositoryFake($this->equipment()),
            new CorrectionAppendRepositoryFake(),
            $corrections,
            $unitOfWork,
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['lecturas.corregir'], [9]),
                new CorrectReadingCommand(10, 81, 900, null, 'Equipo incorrecto', new DateTimeImmutable()),
            );
        } finally {
            self::assertSame(1, $unitOfWork->rollbacks);
            self::assertSame(0, $corrections->annulments);
        }
    }

    public function testRejectsEquipmentInUnauthorizedCurrentBranch(): void
    {
        $unitOfWork = new CorrectionUnitOfWorkFake();
        $handler = new CorrectReadingHandler(
            new CorrectionEquipmentRepositoryFake($this->equipment(branchId: 8)),
            new CorrectionAppendRepositoryFake(),
            new ReadingCorrectionRepositoryFake($this->reading(branchId: 8)),
            $unitOfWork,
        );

        $this->expectException(DomainException::class);
        $handler->execute(
            $this->actor(['lecturas.corregir'], [9]),
            new CorrectReadingCommand(10, 81, 900, null, 'Sucursal no autorizada', new DateTimeImmutable()),
        );
    }

    public function testRejectsAlreadyAnnulledOriginalAndRollsBack(): void
    {
        $original = $this->reading(annulled: true);
        $append = new CorrectionAppendRepositoryFake();
        $unitOfWork = new CorrectionUnitOfWorkFake();
        $handler = new CorrectReadingHandler(
            new CorrectionEquipmentRepositoryFake($this->equipment()),
            $append,
            new ReadingCorrectionRepositoryFake($original),
            $unitOfWork,
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['lecturas.corregir'], [9]),
                new CorrectReadingCommand(10, 81, 900, null, 'Nuevo intento inválido', new DateTimeImmutable()),
            );
        } finally {
            self::assertNull($append->appended);
            self::assertSame(1, $unitOfWork->rollbacks);
        }
    }

    public function testRequiresCorrectionPermissionBeforeOpeningTransaction(): void
    {
        $unitOfWork = new CorrectionUnitOfWorkFake();
        $handler = new CorrectReadingHandler(
            new CorrectionEquipmentRepositoryFake($this->equipment()),
            new CorrectionAppendRepositoryFake(),
            new ReadingCorrectionRepositoryFake($this->reading()),
            $unitOfWork,
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['lecturas.cargar'], [9]),
                new CorrectReadingCommand(10, 81, 900, null, 'Sin permiso específico', new DateTimeImmutable()),
            );
        } finally {
            self::assertSame(0, $unitOfWork->begins);
        }
    }

    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Responsable'], $permissions, $branches);
    }

    private function equipment(int $branchId = 9): Equipment
    {
        return Equipment::reconstitute(
            10,
            5,
            $branchId,
            new EquipmentType(3, 'Camión', true, true),
            'CAM-01',
            null,
            Equipment::ACTIVE,
            new DateTimeImmutable('2026-08-01'),
            null,
            null,
            1400,
            '35.5',
        );
    }

    private function reading(int $branchId = 7, int $equipmentId = 10, bool $annulled = false): EquipmentReading
    {
        return EquipmentReading::reconstitute(
            81,
            5,
            $branchId,
            $equipmentId,
            new DateTimeImmutable('2026-07-10 08:30:00'),
            UsageMeasurement::from(1000, '20.0'),
            EquipmentReading::MANUAL,
            null,
            4,
            null,
            null,
            null,
            $annulled,
            $annulled ? new DateTimeImmutable('2026-08-01') : null,
            $annulled ? 9 : null,
            $annulled ? 'Corrección anterior' : null,
        );
    }
}

final class CorrectionEquipmentRepositoryFake implements EquipmentRepository
{
    public function __construct(private ?Equipment $equipment) {}
    public function codeExists(int $companyId, string $code): bool { return false; }
    public function add(Equipment $equipment, int $actorUserId): int { return 1; }
    public function findForUpdate(int $equipmentId, int $companyId): ?Equipment
    {
        return $this->equipment?->id() === $equipmentId && $this->equipment->companyId() === $companyId
            ? $this->equipment
            : null;
    }
    public function updateCurrentUsage(Equipment $equipment, int $actorUserId): void {}
}

final class CorrectionAppendRepositoryFake implements ReadingRepository
{
    public ?EquipmentReading $appended = null;
    public function append(EquipmentReading $reading): int
    {
        $this->appended = $reading;
        return 82;
    }
}

final class ReadingCorrectionRepositoryFake implements ReadingCorrectionRepository
{
    public int $annulments = 0;
    public int $projectionRecalculations = 0;
    public function __construct(
        private ?EquipmentReading $reading,
        private ?UsageMeasurement $current = null,
    ) {
    }
    public function findForUpdate(int $readingId, int $companyId, int $equipmentId): ?EquipmentReading
    {
        return $this->reading?->id() === $readingId
            && $this->reading->companyId() === $companyId
            && $this->reading->equipmentId() === $equipmentId
                ? $this->reading
                : null;
    }
    public function markAnnulled(EquipmentReading $reading): void { $this->annulments++; }
    public function recalculateCurrentUsage(int $companyId, int $branchId, int $equipmentId, int $actorUserId): UsageMeasurement
    {
        $this->projectionRecalculations++;
        return $this->current ?? UsageMeasurement::from(950, '19.5');
    }
}

final class CorrectionUnitOfWorkFake implements UnitOfWork
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public function transactional(callable $operation): mixed
    {
        $this->begins++;
        try {
            $result = $operation();
            $this->commits++;
            return $result;
        } catch (Throwable $exception) {
            $this->rollbacks++;
            throw $exception;
        }
    }
}

final readonly class CorrectionWorkOrderSynchronizerFake implements WorkOrderReadingCorrectionSynchronizer
{
    public function __construct(private NotifiableEvent $event) {}

    public function synchronizeFinalizedWorkOrder(
        EquipmentReading $original,
        UsageMeasurement $replacement,
        int $correctionReadingId,
        int $actorUserId,
        string $reason,
        ?string $notes,
        DateTimeImmutable $correctedAt,
    ): ?NotifiableEvent {
        return $this->event;
    }
}

final class CorrectionNotificationPublisherFake implements NotifiableEventPublisher
{
    public ?NotifiableEvent $published = null;

    public function publish(NotifiableEvent $event): void
    {
        $this->published = $event;
    }
}
