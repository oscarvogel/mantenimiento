<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\RegisterTripCashMovement;
use App\Application\Trips\RegisterTripCashMovementCommand;
use App\Application\Trips\RegisterTripExpense;
use App\Application\Trips\RegisterTripExpenseCommand;
use App\Application\Trips\RegisterTripFuel;
use App\Application\Trips\RegisterTripFuelCommand;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripExpense;
use App\Domain\Trips\TripFuelLoad;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class RegisterTripCostsTest extends TestCase
{
    public function testRegistersFuelInsideTripScope(): void
    {
        $costs = new CostRepositoryFake();
        $handler = new RegisterTripFuel(new CostTripRepositoryFake($this->trip()), $costs, new CostDirectoryFake(), new CostUnitOfWorkFake());

        $id = $handler->execute($this->actor(['viajes.operar']), new RegisterTripFuelCommand(
            55,
            new DateTimeImmutable('2026-10-20 10:00:00'),
            100.0,
            50_000.0,
            'ARS',
            1.0,
            145_000,
            'YPF',
            'A-1',
            'Carga en ruta',
        ));

        self::assertSame(1, $id);
        self::assertInstanceOf(TripFuelLoad::class, $costs->fuel);
        self::assertSame(100.0, $costs->fuel->liters());
        self::assertSame(55, $costs->tripId);
        self::assertSame(5, $costs->companyId);
        self::assertSame(10, $costs->branchId);
    }

    public function testRegistersExpenseAndRejectsUnknownCategory(): void
    {
        $costs = new CostRepositoryFake();
        $handler = new RegisterTripExpense(new CostTripRepositoryFake($this->trip()), $costs, new CostDirectoryFake(), new CostUnitOfWorkFake());

        $handler->execute($this->actor(['viajes.operar']), new RegisterTripExpenseCommand(
            55,
            new DateTimeImmutable('2026-10-20 11:00:00'),
            20_000.0,
            3,
            'ARS',
            1.0,
            2,
            true,
            'T-1',
            'Peaje',
        ));
        self::assertInstanceOf(TripExpense::class, $costs->expense);
        self::assertTrue($costs->expense->paidFromCash());

        $directory = new CostDirectoryFake();
        $directory->categoryExists = false;
        $handler = new RegisterTripExpense(new CostTripRepositoryFake($this->trip()), $costs, $directory, new CostUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('categoría');
        $handler->execute($this->actor(['viajes.operar']), new RegisterTripExpenseCommand(
            55,
            new DateTimeImmutable('2026-10-20 11:00:00'),
            20_000.0,
            99,
        ));
    }

    public function testRegistersCashMovementAndRejectsInvalidType(): void
    {
        $costs = new CostRepositoryFake();
        $handler = new RegisterTripCashMovement(new CostTripRepositoryFake($this->trip()), $costs, new CostDirectoryFake(), new CostUnitOfWorkFake());

        $handler->execute($this->actor(['viajes.operar']), new RegisterTripCashMovementCommand(
            55,
            TripCashMovement::ANTICIPO,
            new DateTimeImmutable('2026-10-20 07:00:00'),
            100_000.0,
        ));
        self::assertInstanceOf(TripCashMovement::class, $costs->cashMovement);

        $this->expectException(DomainException::class);
        $handler->execute($this->actor(['viajes.operar']), new RegisterTripCashMovementCommand(
            55,
            'OTRO',
            new DateTimeImmutable('2026-10-20 07:00:00'),
            100_000.0,
        ));
    }

    public function testRejectsActorWithoutOperatePermission(): void
    {
        $handler = new RegisterTripFuel(new CostTripRepositoryFake($this->trip()), new CostRepositoryFake(), new CostDirectoryFake(), new CostUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $handler->execute($this->actor(['viajes.ver']), new RegisterTripFuelCommand(
            55,
            new DateTimeImmutable('2026-10-20 10:00:00'),
            100.0,
            50_000.0,
        ));
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(1, 5, false, true, [], $permissions, []);
    }

    private function trip(): Trip
    {
        return Trip::reconstitute(
            55,
            5,
            10,
            42,
            Trip::PLANIFICADO,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            null,
            null,
            null,
            null,
            [
                new TripAssignment(TripAssignment::TRACTOR, 7, new DateTimeImmutable('2026-10-20 08:00:00')),
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-10-20 08:00:00')),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Rosario'),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba'),
            ],
            '',
        );
    }
}

final class CostTripRepositoryFake implements OperationalTripRepository
{
    public function __construct(private readonly Trip $trip)
    {
    }

    public function nextNumber(int $companyId): int
    {
        return 1;
    }

    public function add(Trip $trip, int $actorUserId): int
    {
        return 1;
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip
    {
        return $this->trip;
    }

    public function save(Trip $trip, int $actorUserId): void
    {
    }

    public function conflicts(
        int $companyId,
        array $equipmentIds,
        array $employeeIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $excludeTripId = null,
    ): array {
        return [];
    }
}

final class CostRepositoryFake implements TripCostRepository
{
    public ?TripFuelLoad $fuel = null;
    public ?TripExpense $expense = null;
    public ?TripCashMovement $cashMovement = null;
    public int $tripId = 0;
    public int $companyId = 0;
    public int $branchId = 0;

    public function addFuel(int $tripId, int $companyId, int $branchId, TripFuelLoad $load, int $actorUserId): int
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->branchId = $branchId;
        $this->fuel = $load;

        return 1;
    }

    public function addExpense(int $tripId, int $companyId, int $branchId, TripExpense $expense, int $actorUserId): int
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->branchId = $branchId;
        $this->expense = $expense;

        return 1;
    }

    public function addCashMovement(int $tripId, int $companyId, int $branchId, TripCashMovement $movement, int $actorUserId): int
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->branchId = $branchId;
        $this->cashMovement = $movement;

        return 1;
    }
}

final class CostDirectoryFake implements TripDirectoryLookup
{
    public bool $categoryExists = true;

    public function customerExists(int $companyId, int $customerId): bool { return true; }
    public function loadUnitExists(int $companyId, int $loadUnitId): bool { return true; }
    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool { return true; }
    public function expenseCategoryExists(int $companyId, int $categoryId): bool { return $this->categoryExists; }
    public function paymentMethodExists(int $companyId, int $paymentMethodId): bool { return true; }
    public function currencyExists(string $code): bool { return true; }
}

final class CostUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
