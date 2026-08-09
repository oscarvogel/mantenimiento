<?php

declare(strict_types=1);

use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Identity\ActorContext;
use App\Application\Measurement\Port\ReadingRepository;
use App\Application\Measurement\Port\UnitOfWork;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\Measurement\RegisterReadingHandler;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use App\Domain\Measurement\EquipmentReading;
use PHPUnit\Framework\TestCase;

final class RegisterReadingHandlerTest extends TestCase
{
    public function testPersistsReadingAndCurrentUsageInsideOneTransaction(): void
    {
        $equipment = $this->equipment(1000, '20.0');
        $equipmentRepository = new ReadingEquipmentRepositoryFake($equipment);
        $readings = new ReadingRepositoryFake();
        $unitOfWork = new MeasurementUnitOfWorkFake();
        $handler = new RegisterReadingHandler($equipmentRepository, $readings, $unitOfWork);

        $result = $handler->execute(
            $this->actor(['lecturas.cargar'], [7]),
            new RegisterReadingCommand(10, new DateTimeImmutable('2026-08-08 10:00:00'), 1200, null, EquipmentReading::MANUAL),
        );

        self::assertSame(73, $result->readingId);
        self::assertSame(1200, $result->currentKilometers);
        self::assertSame('20.0', $result->currentHours);
        self::assertFalse($result->correction);
        self::assertSame(1, $unitOfWork->commits);
        self::assertSame(0, $unitOfWork->rollbacks);
        self::assertSame(1, $equipmentRepository->updates);
        self::assertSame(1200, $readings->appended?->measurement()->kilometers());
    }

    public function testRejectsRegressionWithoutCorrectionPermissionAndRollsBack(): void
    {
        $equipmentRepository = new ReadingEquipmentRepositoryFake($this->equipment(1000, null));
        $readings = new ReadingRepositoryFake();
        $unitOfWork = new MeasurementUnitOfWorkFake();
        $handler = new RegisterReadingHandler($equipmentRepository, $readings, $unitOfWork);

        try {
            $handler->execute(
                $this->actor(['lecturas.cargar'], [7]),
                new RegisterReadingCommand(10, new DateTimeImmutable(), 900, null, EquipmentReading::MANUAL, correctionReason: 'Error de carga'),
            );
            self::fail('La lectura inferior debió rechazarse.');
        } catch (DomainException) {
            self::assertSame(1, $unitOfWork->rollbacks);
            self::assertNull($readings->appended);
            self::assertSame(0, $equipmentRepository->updates);
        }
    }

    public function testAuthorizedRegressionPreservesCorrectionReason(): void
    {
        $equipmentRepository = new ReadingEquipmentRepositoryFake($this->equipment(1000, null));
        $readings = new ReadingRepositoryFake();
        $handler = new RegisterReadingHandler($equipmentRepository, $readings, new MeasurementUnitOfWorkFake());

        $result = $handler->execute(
            $this->actor(['lecturas.cargar', 'lecturas.corregir'], [7]),
            new RegisterReadingCommand(
                10,
                new DateTimeImmutable(),
                900,
                null,
                EquipmentReading::MANUAL,
                correctionReason: 'Corrección del valor anterior',
            ),
        );

        self::assertTrue($result->correction);
        self::assertSame('Corrección del valor anterior', $readings->appended?->correctionReason());
    }

    public function testPersistenceFailureRollsBackBeforeUpdatingSnapshot(): void
    {
        $equipmentRepository = new ReadingEquipmentRepositoryFake($this->equipment(1000, null));
        $readings = new ReadingRepositoryFake();
        $readings->fail = true;
        $unitOfWork = new MeasurementUnitOfWorkFake();
        $handler = new RegisterReadingHandler($equipmentRepository, $readings, $unitOfWork);

        try {
            $handler->execute(
                $this->actor(['lecturas.cargar'], [7]),
                new RegisterReadingCommand(10, new DateTimeImmutable(), 1100, null, EquipmentReading::MANUAL),
            );
            self::fail('La falla simulada debió propagarse.');
        } catch (RuntimeException) {
            self::assertSame(1, $unitOfWork->rollbacks);
            self::assertSame(0, $equipmentRepository->updates);
        }
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Técnico u operador'], $permissions, $branches);
    }

    private function equipment(?int $kilometers, int|float|string|null $hours): Equipment
    {
        return Equipment::reconstitute(
            10,
            5,
            7,
            new EquipmentType(3, 'Camión', true, true),
            'CAM-01',
            null,
            Equipment::ACTIVE,
            new DateTimeImmutable('2026-08-01'),
            null,
            null,
            $kilometers,
            $hours,
        );
    }
}

final class ReadingEquipmentRepositoryFake implements EquipmentRepository
{
    public int $updates = 0;
    public function __construct(private ?Equipment $equipment) {}
    public function codeExists(int $companyId, string $code): bool { return false; }
    public function add(Equipment $equipment, int $actorUserId): int { return 1; }
    public function findForUpdate(int $equipmentId, int $companyId): ?Equipment
    {
        return $this->equipment?->id() === $equipmentId && $this->equipment->companyId() === $companyId
            ? $this->equipment
            : null;
    }
    public function updateCurrentUsage(Equipment $equipment, int $actorUserId): void { $this->updates++; }
}

final class ReadingRepositoryFake implements ReadingRepository
{
    public bool $fail = false;
    public ?EquipmentReading $appended = null;
    public function append(EquipmentReading $reading): int
    {
        if ($this->fail) { throw new RuntimeException('Falla simulada.'); }
        $this->appended = $reading;
        return 73;
    }
}

final class MeasurementUnitOfWorkFake implements UnitOfWork
{
    public int $commits = 0;
    public int $rollbacks = 0;
    public function transactional(callable $operation): mixed
    {
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
