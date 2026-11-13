<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripLogbookRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\SaveTripLogbookEntry;
use App\Application\Trips\SaveTripLogbookEntryCommand;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripExpense;
use App\Domain\Trips\TripFuelLoad;
use App\Domain\Trips\TripLogbookEntry;
use App\Domain\Trips\TripOtherEarning;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class SaveTripLogbookEntryTest extends TestCase
{
    public function testSavesEntryAndGeneratesEarningAutomatically(): void
    {
        $logbook = new LogbookRepositoryFake();
        $costs = new LogbookCostRepositoryFake();
        $handler = $this->handler($logbook, $costs);

        $entryId = $handler->execute($this->actor(), $this->command('2026-10-17', '2026-10-23'));

        self::assertSame(1, $entryId);
        self::assertSame(6, $logbook->entry?->presenceDays());
        self::assertSame(4.0, $logbook->entry?->calculatedDays());
        self::assertSame(1, $costs->logbookEntryId);
        self::assertInstanceOf(TripOtherEarning::class, $costs->earning);
        self::assertSame(4.0, $costs->earning->quantity());
        self::assertSame(50_000.0, $costs->earning->unitAmount());
        self::assertSame(200_000.0, $costs->earning->money()->amount());
        self::assertSame('Estadía Sider', $costs->earning->concept());
    }

    public function testManualCorrectionUpdatesTheLinkedEarning(): void
    {
        $logbook = new LogbookRepositoryFake();
        $logbook->linkedEarningId = 77;
        $costs = new LogbookCostRepositoryFake();
        $handler = $this->handler($logbook, $costs);

        $handler->execute($this->actor(), $this->command('2026-10-17', '2026-10-23', adjustedDays: 3.0, reason: 'Acuerdo con el cliente'));

        self::assertSame(3.0, $costs->earning?->quantity());
        self::assertSame(150_000.0, $costs->earning?->money()->amount());
        self::assertSame(77, $costs->movementId);
        self::assertTrue($costs->updated);
    }

    public function testFreeTimeNotExceededDoesNotGenerateEarning(): void
    {
        $logbook = new LogbookRepositoryFake();
        $costs = new LogbookCostRepositoryFake();
        $handler = $this->handler($logbook, $costs);

        $handler->execute($this->actor(), $this->command('2026-10-16', '2026-10-16'));

        self::assertNull($costs->earning);
    }

    public function testZeroDaysDeletesPreviouslyLinkedEarning(): void
    {
        $logbook = new LogbookRepositoryFake();
        $logbook->linkedEarningId = 88;
        $costs = new LogbookCostRepositoryFake();
        $handler = $this->handler($logbook, $costs);

        $handler->execute($this->actor(), $this->command('2026-10-16', '2026-10-16'));

        self::assertTrue($costs->deleted);
        self::assertSame(88, $costs->movementId);
    }

    public function testRejectsConceptThatIsNotMeasuredInDays(): void
    {
        $directory = new LogbookDirectoryFake();
        $directory->concept['unidad'] = 'UNIDAD';
        $handler = $this->handler(new LogbookRepositoryFake(), new LogbookCostRepositoryFake(), $directory);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('por día');

        $handler->execute($this->actor(), $this->command('2026-10-17', '2026-10-23'));
    }

    public function testRejectsDriverNotAssignedToTrip(): void
    {
        $handler = $this->handler(new LogbookRepositoryFake(), new LogbookCostRepositoryFake());
        $command = $this->command('2026-10-17', '2026-10-23', employeeId: 99);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no está asignado');

        $handler->execute($this->actor(), $command);
    }

    private function handler(
        LogbookRepositoryFake $logbook,
        LogbookCostRepositoryFake $costs,
        ?LogbookDirectoryFake $directory = null,
    ): SaveTripLogbookEntry {
        return new SaveTripLogbookEntry(
            new LogbookTripRepositoryFake($this->trip()),
            $logbook,
            $costs,
            new LogbookFleetFake(),
            $directory ?? new LogbookDirectoryFake(),
            new LogbookUnitOfWorkFake(),
        );
    }

    private function command(
        string $arrival,
        ?string $departure,
        ?float $adjustedDays = null,
        ?string $reason = null,
        int $employeeId = 11,
    ): SaveTripLogbookEntryCommand {
        return new SaveTripLogbookEntryCommand(
            55,
            TripLogbookEntry::FRONTERA,
            'Frontera Brasil',
            new DateTimeImmutable($arrival),
            $departure === null ? null : new DateTimeImmutable($departure),
            48,
            $employeeId,
            9,
            $adjustedDays,
            $reason,
        );
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], ['viajes.operar'], [10]);
    }

    private function trip(): Trip
    {
        return Trip::reconstitute(
            55,
            5,
            10,
            42,
            Trip::EN_CURSO,
            new DateTimeImmutable('2026-10-16 08:00:00'),
            null,
            new DateTimeImmutable('2026-10-16 08:00:00'),
            null,
            100_000,
            null,
            [
                new TripAssignment(TripAssignment::TRACTOR, 7, new DateTimeImmutable('2026-10-16 08:00:00')),
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-10-16 08:00:00')),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Rosario'),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba'),
            ],
            '',
        );
    }
}

final class LogbookTripRepositoryFake implements OperationalTripRepository
{
    public function __construct(private readonly Trip $trip)
    {
    }

    public function nextNumber(int $companyId): int { return 1; }
    public function add(Trip $trip, int $actorUserId): int { return 1; }
    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip { return $this->trip; }
    public function save(Trip $trip, int $actorUserId): void {}
    public function updateDetails(Trip $trip, int $actorUserId): void {}
    public function updateDriverPercentage(int $companyId, int $tripId, int $employeeId, float $percentage, int $actorUserId): void {}
    public function conflicts(int $companyId, array $equipmentIds, array $employeeIds, DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $excludeTripId = null): array { return []; }
    public function updatePlannedReturn(int $companyId, int $tripId, ?DateTimeImmutable $returnAt, int $actorUserId): void {}
}

final class LogbookRepositoryFake implements TripLogbookRepository
{
    public ?TripLogbookEntry $entry = null;
    public ?int $linkedEarningId = null;
    public int $nextOrder = 1;
    public bool $deleted = false;

    public function nextOrder(int $tripId, int $companyId): int { return $this->nextOrder; }
    public function add(int $tripId, int $companyId, int $branchId, TripLogbookEntry $entry, int $actorUserId): int { $this->entry = $entry; return 1; }
    public function update(int $tripId, int $companyId, int $entryId, TripLogbookEntry $entry, int $actorUserId): bool { $this->entry = $entry; return true; }
    public function softDelete(int $tripId, int $companyId, int $entryId, int $actorUserId): bool { $this->deleted = true; return true; }
    public function linkedEarningId(int $tripId, int $companyId, int $entryId): ?int { return $this->linkedEarningId; }
}

final class LogbookCostRepositoryFake implements TripCostRepository
{
    public ?TripOtherEarning $earning = null;
    public ?int $logbookEntryId = null;
    public int $movementId = 0;
    public bool $updated = false;
    public bool $deleted = false;

    public function addFuel(int $tripId, int $companyId, int $branchId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): int { return 1; }
    public function addExpense(int $tripId, int $companyId, int $branchId, TripExpense $expense, int $actorUserId, ?int $legId = null): int { return 1; }
    public function addCashMovement(int $tripId, int $companyId, int $branchId, TripCashMovement $movement, int $actorUserId): int { return 1; }
    public function updateFuel(int $tripId, int $companyId, int $movementId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): bool { return true; }
    public function updateExpense(int $tripId, int $companyId, int $movementId, TripExpense $expense, int $actorUserId, ?int $legId = null): bool { return true; }
    public function updateCashMovement(int $tripId, int $companyId, int $movementId, TripCashMovement $movement, int $actorUserId): bool { return true; }
    public function softDeleteFuel(int $tripId, int $companyId, int $movementId, int $actorUserId): bool { return true; }
    public function softDeleteExpense(int $tripId, int $companyId, int $movementId, int $actorUserId): bool { return true; }
    public function softDeleteCashMovement(int $tripId, int $companyId, int $movementId, int $actorUserId): bool { return true; }

    public function addOtherEarning(int $tripId, int $companyId, int $branchId, TripOtherEarning $earning, int $actorUserId, ?int $logbookEntryId = null): int
    {
        $this->earning = $earning;
        $this->logbookEntryId = $logbookEntryId;
        return 1;
    }

    public function updateOtherEarning(int $tripId, int $companyId, int $movementId, TripOtherEarning $earning, int $actorUserId, ?int $logbookEntryId = null): bool
    {
        $this->earning = $earning;
        $this->movementId = $movementId;
        $this->logbookEntryId = $logbookEntryId;
        $this->updated = true;
        return true;
    }

    public function softDeleteOtherEarning(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        $this->movementId = $movementId;
        $this->deleted = true;
        return true;
    }

    public function logbookEntryIdForEarning(int $tripId, int $companyId, int $movementId): ?int { return null; }
}

final class LogbookFleetFake implements TripFleetLookup
{
    public function equipment(int $companyId, int $equipmentId): ?array { return null; }
    public function driver(int $companyId, int $employeeId): ?array { return ['id' => $employeeId, 'label' => 'Chofer', 'active' => true]; }
}

final class LogbookDirectoryFake implements TripDirectoryLookup
{
    public array $concept = [
        'id' => 9,
        'nombre' => 'Estadía Sider',
        'categoria' => 'HABER',
        'unidad' => 'DIA',
        'importe_unitario' => 50_000,
        'moneda' => 'ARS',
        'permite_editar_importe' => false,
    ];

    public function customerExists(int $companyId, int $customerId): bool { return true; }
    public function loadUnitExists(int $companyId, int $loadUnitId): bool { return true; }
    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool { return true; }
    public function expenseCategoryExists(int $companyId, int $categoryId): bool { return true; }
    public function paymentMethodExists(int $companyId, int $paymentMethodId): bool { return true; }
    public function concept(int $companyId, int $conceptId): ?array { return $this->concept; }
    public function currencyExists(string $code): bool { return true; }
}

final class LogbookUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
