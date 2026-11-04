<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\RegisterTripCashMovement;
use App\Application\Trips\RegisterTripCashMovementCommand;
use App\Application\Trips\RegisterTripExpense;
use App\Application\Trips\RegisterTripExpenseCommand;
use App\Application\Trips\RegisterTripFuel;
use App\Application\Trips\RegisterTripFuelCommand;
use App\Application\Trips\RegisterTripOtherEarning;
use App\Application\Trips\RegisterTripOtherEarningCommand;
use App\Application\Trips\DeleteOperationalTripCost;
use App\Application\Trips\DeleteOperationalTripCostCommand;
use App\Application\Trips\UpdateTripOtherEarning;
use App\Application\Trips\UpdateTripOtherEarningCommand;
use App\Application\Trips\UpdateTripDriverPercentage;
use App\Application\Trips\UpdateTripDriverPercentageCommand;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripExpense;
use App\Domain\Trips\TripFuelLoad;
use App\Domain\Trips\TripOtherEarning;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class RegisterTripCostsTest extends TestCase
{
    public function testRegistersFuelInsideTripScope(): void
    {
        $costs = new CostRepositoryFake();
        $handler = new RegisterTripFuel(new CostTripRepositoryFake($this->trip()), $costs, new CostDirectoryFake(), new CostLegRepositoryFake(), new CostUnitOfWorkFake());

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
        $handler = new RegisterTripExpense(new CostTripRepositoryFake($this->trip()), $costs, new CostDirectoryFake(), new CostLegRepositoryFake(), new CostUnitOfWorkFake());

        $handler->execute($this->actor(['viajes.operar']), new RegisterTripExpenseCommand(
            55,
            new DateTimeImmutable('2026-10-20 11:00:00'),
            20_000.0,
            3,
            'ARS',
            1.0,
            2,
            false,
            'T-1',
            'Peaje',
            null,
            'CHOFER',
        ));
        self::assertInstanceOf(TripExpense::class, $costs->expense);
        self::assertSame('CHOFER', $costs->expense->paidBy());
        self::assertFalse($costs->expense->paidFromCash());

        $directory = new CostDirectoryFake();
        $directory->categoryExists = false;
        $handler = new RegisterTripExpense(new CostTripRepositoryFake($this->trip()), $costs, $directory, new CostLegRepositoryFake(), new CostUnitOfWorkFake());

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

    public function testRegistersOtherEarningForAssignedDriver(): void
    {
        $costs = new CostRepositoryFake();
        $handler = new RegisterTripOtherEarning(
            new CostTripRepositoryFake($this->trip()),
            $costs,
            new CostFleetFake(),
            new CostDirectoryFake(),
            new CostUnitOfWorkFake(),
        );

        $id = $handler->execute($this->actor(['viajes.operar']), new RegisterTripOtherEarningCommand(
            55,
            11,
            new DateTimeImmutable('2026-10-20'),
            25_000.0,
            'ARS',
            1.0,
            'Espera en descarga',
        ));

        self::assertSame(1, $id);
        self::assertInstanceOf(TripOtherEarning::class, $costs->otherEarning);
        self::assertSame(11, $costs->otherEarning->employeeId());
        self::assertSame(25_000.0, $costs->otherEarning->money()->amount());
    }

    public function testRejectsOtherEarningForDriverNotAssignedToTrip(): void
    {
        $handler = new RegisterTripOtherEarning(
            new CostTripRepositoryFake($this->trip()),
            new CostRepositoryFake(),
            new CostFleetFake(),
            new CostDirectoryFake(),
            new CostUnitOfWorkFake(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no está asignado');
        $handler->execute($this->actor(['viajes.operar']), new RegisterTripOtherEarningCommand(
            55,
            99,
            new DateTimeImmutable('2026-10-20'),
            25_000.0,
        ));
    }

    public function testRejectsActorWithoutOperatePermission(): void
    {
        $handler = new RegisterTripFuel(new CostTripRepositoryFake($this->trip()), new CostRepositoryFake(), new CostDirectoryFake(), new CostLegRepositoryFake(), new CostUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $handler->execute($this->actor(['viajes.ver']), new RegisterTripFuelCommand(
            55,
            new DateTimeImmutable('2026-10-20 10:00:00'),
            100.0,
            50_000.0,
        ));
    }

    public function testExpenseWithFixedConceptCalculatesAmountFromFractionalQuantity(): void
    {
        $costs = new CostRepositoryFake();
        $directory = new CostDirectoryFake();
        $directory->concept = [
            'id' => 9,
            'nombre' => 'Estadía Sider',
            'categoria' => 'GASTO',
            'unidad' => 'DIA',
            'importe_unitario' => 50_000.0,
            'moneda' => 'ARS',
            'permite_editar_importe' => false,
        ];
        $handler = new RegisterTripExpense(new CostTripRepositoryFake($this->trip()), $costs, $directory, new CostLegRepositoryFake(), new CostUnitOfWorkFake());

        $handler->execute($this->actor(['viajes.operar']), new RegisterTripExpenseCommand(
            tripId: 55,
            date: new DateTimeImmutable('2026-10-20 11:00:00'),
            amount: 1.0,
            conceptId: 9,
            quantity: 1.5,
        ));

        self::assertInstanceOf(TripExpense::class, $costs->expense);
        self::assertSame(75_000.0, $costs->expense->money()->amount());
        self::assertSame(9, $costs->expense->conceptId());
        self::assertSame(1.5, $costs->expense->quantity());
        self::assertSame(50_000.0, $costs->expense->unitAmount());
    }

    public function testExpenseWithEditableConceptKeepsPostedAmountButSnapshotsUnitAmount(): void
    {
        $costs = new CostRepositoryFake();
        $directory = new CostDirectoryFake();
        $directory->concept = [
            'id' => 9,
            'nombre' => 'Peaje',
            'categoria' => 'AMBOS',
            'unidad' => 'UNIDAD',
            'importe_unitario' => 1_000.0,
            'moneda' => 'ARS',
            'permite_editar_importe' => true,
        ];
        $handler = new RegisterTripExpense(new CostTripRepositoryFake($this->trip()), $costs, $directory, new CostLegRepositoryFake(), new CostUnitOfWorkFake());

        $handler->execute($this->actor(['viajes.operar']), new RegisterTripExpenseCommand(
            tripId: 55,
            date: new DateTimeImmutable('2026-10-20 11:00:00'),
            amount: 1_800.0,
            conceptId: 9,
            quantity: 2.0,
        ));

        self::assertSame(1_800.0, $costs->expense->money()->amount());
        self::assertSame(1_000.0, $costs->expense->unitAmount());
    }

    public function testExpenseRejectsEarningConcept(): void
    {
        $directory = new CostDirectoryFake();
        $directory->concept = [
            'id' => 9,
            'nombre' => 'Estadía Sider',
            'categoria' => 'HABER',
            'unidad' => 'DIA',
            'importe_unitario' => 50_000.0,
            'moneda' => 'ARS',
            'permite_editar_importe' => false,
        ];
        $handler = new RegisterTripExpense(new CostTripRepositoryFake($this->trip()), new CostRepositoryFake(), $directory, new CostLegRepositoryFake(), new CostUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no aplica a gastos');
        $handler->execute($this->actor(['viajes.operar']), new RegisterTripExpenseCommand(
            tripId: 55,
            date: new DateTimeImmutable('2026-10-20 11:00:00'),
            amount: 1_000.0,
            conceptId: 9,
        ));
    }

    public function testOtherEarningWithFixedConceptComputesTotalAndUsesCatalogName(): void
    {
        $costs = new CostRepositoryFake();
        $directory = new CostDirectoryFake();
        $directory->concept = [
            'id' => 9,
            'nombre' => 'Estadía Sider',
            'categoria' => 'HABER',
            'unidad' => 'DIA',
            'importe_unitario' => 50_000.0,
            'moneda' => 'ARS',
            'permite_editar_importe' => false,
        ];
        $handler = new RegisterTripOtherEarning(
            new CostTripRepositoryFake($this->trip()),
            $costs,
            new CostFleetFake(),
            $directory,
            new CostUnitOfWorkFake(),
        );

        $handler->execute($this->actor(['viajes.operar']), new RegisterTripOtherEarningCommand(
            tripId: 55,
            employeeId: 11,
            date: new DateTimeImmutable('2026-10-20'),
            amount: 1.0,
            conceptId: 9,
            quantity: 1.5,
        ));

        self::assertSame(75_000.0, $costs->otherEarning->money()->amount());
        self::assertSame('Estadía Sider', $costs->otherEarning->concept());
        self::assertSame(9, $costs->otherEarning->conceptId());
        self::assertSame(1.5, $costs->otherEarning->quantity());
        self::assertSame(50_000.0, $costs->otherEarning->unitAmount());
    }

    public function testOtherEarningRejectsExpenseConcept(): void
    {
        $directory = new CostDirectoryFake();
        $directory->concept = [
            'id' => 9,
            'nombre' => 'Peaje',
            'categoria' => 'GASTO',
            'unidad' => 'UNIDAD',
            'importe_unitario' => 1_000.0,
            'moneda' => 'ARS',
            'permite_editar_importe' => false,
        ];
        $handler = new RegisterTripOtherEarning(
            new CostTripRepositoryFake($this->trip()),
            new CostRepositoryFake(),
            new CostFleetFake(),
            $directory,
            new CostUnitOfWorkFake(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no aplica a haberes');
        $handler->execute($this->actor(['viajes.operar']), new RegisterTripOtherEarningCommand(
            tripId: 55,
            employeeId: 11,
            date: new DateTimeImmutable('2026-10-20'),
            amount: 1_000.0,
            conceptId: 9,
        ));
    }

    public function testUpdatesOtherEarningWithFixedConcept(): void
    {
        $costs = new CostRepositoryFake();
        $directory = new CostDirectoryFake();
        $directory->concept = [
            'id' => 9,
            'nombre' => 'Estadía Cámara de frío',
            'categoria' => 'HABER',
            'unidad' => 'DIA',
            'importe_unitario' => 80_000.0,
            'moneda' => 'ARS',
            'permite_editar_importe' => false,
        ];
        $handler = new UpdateTripOtherEarning(
            new CostTripRepositoryFake($this->trip()),
            $costs,
            new CostFleetFake(),
            $directory,
            new CostUnitOfWorkFake(),
        );

        $handler->execute($this->actor(['viajes.operar']), new UpdateTripOtherEarningCommand(
            tripId: 55,
            movementId: 77,
            employeeId: 11,
            date: new DateTimeImmutable('2026-10-20'),
            amount: 1.0,
            currency: 'ARS',
            exchangeRate: 1.0,
            conceptId: 9,
            quantity: 1.5,
            concept: '',
            notes: null,
        ));

        self::assertTrue($costs->otherEarningUpdated);
        self::assertSame(120_000.0, $costs->otherEarning->money()->amount());
        self::assertSame('Estadía Cámara de frío', $costs->otherEarning->concept());
        self::assertSame(9, $costs->otherEarning->conceptId());
    }

    public function testDeletesOtherEarning(): void
    {
        $costs = new CostRepositoryFake();
        $handler = new DeleteOperationalTripCost(
            new CostTripRepositoryFake($this->trip()),
            $costs,
            new CostUnitOfWorkFake(),
        );

        $handler->execute($this->actor(['viajes.operar']), new DeleteOperationalTripCostCommand(
            55,
            77,
            DeleteOperationalTripCost::EARNING,
        ));

        self::assertTrue($costs->otherEarningDeleted);
    }

    public function testUpdatesDriverPercentageForOpenTrip(): void
    {
        $trips = new CostTripRepositoryFake($this->trip());
        $handler = new UpdateTripDriverPercentage($trips, new CostUnitOfWorkFake());

        $handler->execute($this->actor(['viajes.operar']), new UpdateTripDriverPercentageCommand(
            tripId: 55,
            employeeId: 11,
            percentage: 14.0,
        ));

        self::assertSame(14.0, $trips->driverPercentage);
    }

    public function testRejectsDriverPercentageOutsideRange(): void
    {
        $handler = new UpdateTripDriverPercentage(new CostTripRepositoryFake($this->trip()), new CostUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $handler->execute($this->actor(['viajes.operar']), new UpdateTripDriverPercentageCommand(
            tripId: 55,
            employeeId: 11,
            percentage: 101.0,
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
    public ?float $driverPercentage = null;

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

    public function updateDriverPercentage(int $companyId, int $tripId, int $employeeId, float $percentage, int $actorUserId): void
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->driverPercentage = $percentage;
    }

    public function conflicts(
        int $companyId,
        array $equipmentIds,
        array $employeeIds,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to,
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
    public ?TripOtherEarning $otherEarning = null;
    public bool $otherEarningUpdated = false;
    public bool $otherEarningDeleted = false;
    public int $tripId = 0;
    public int $companyId = 0;
    public int $branchId = 0;

    public function addFuel(int $tripId, int $companyId, int $branchId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): int
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->branchId = $branchId;
        $this->fuel = $load;

        return 1;
    }

    public function addExpense(int $tripId, int $companyId, int $branchId, TripExpense $expense, int $actorUserId, ?int $legId = null): int
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

    public function updateFuel(int $tripId, int $companyId, int $movementId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): bool
    {
        $this->fuel = $load;
        return true;
    }

    public function updateExpense(int $tripId, int $companyId, int $movementId, TripExpense $expense, int $actorUserId, ?int $legId = null): bool
    {
        $this->expense = $expense;
        return true;
    }

    public function updateCashMovement(int $tripId, int $companyId, int $movementId, TripCashMovement $movement, int $actorUserId): bool
    {
        $this->cashMovement = $movement;
        return true;
    }

    public function softDeleteFuel(int $tripId, int $companyId, int $movementId, int $actorUserId): bool { return true; }
    public function softDeleteExpense(int $tripId, int $companyId, int $movementId, int $actorUserId): bool { return true; }
    public function softDeleteCashMovement(int $tripId, int $companyId, int $movementId, int $actorUserId): bool { return true; }

    public function addOtherEarning(int $tripId, int $companyId, int $branchId, TripOtherEarning $earning, int $actorUserId): int
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->branchId = $branchId;
        $this->otherEarning = $earning;

        return 1;
    }

    public function updateOtherEarning(int $tripId, int $companyId, int $movementId, TripOtherEarning $earning, int $actorUserId): bool
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->otherEarning = $earning;
        $this->otherEarningUpdated = true;

        return true;
    }

    public function softDeleteOtherEarning(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        $this->tripId = $tripId;
        $this->companyId = $companyId;
        $this->otherEarningDeleted = true;

        return true;
    }
}

final class CostFleetFake implements TripFleetLookup
{
    public function equipment(int $companyId, int $equipmentId): ?array
    {
        return null;
    }

    public function driver(int $companyId, int $employeeId): ?array
    {
        return ['id' => $employeeId, 'label' => 'Chofer', 'active' => true];
    }
}

final class CostDirectoryFake implements TripDirectoryLookup
{
    public bool $categoryExists = true;
    public ?array $concept = null;

    public function customerExists(int $companyId, int $customerId): bool { return true; }
    public function loadUnitExists(int $companyId, int $loadUnitId): bool { return true; }
    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool { return true; }
    public function expenseCategoryExists(int $companyId, int $categoryId): bool { return $this->categoryExists; }
    public function paymentMethodExists(int $companyId, int $paymentMethodId): bool { return true; }
    public function concept(int $companyId, int $conceptId): ?array { return $this->concept; }
    public function currencyExists(string $code): bool { return true; }
}

final class CostLegRepositoryFake implements TripLegRepository
{
    public function add(int $companyId, int $branchId, int $tripId, \App\Domain\Trips\TripLeg $leg, int $actorUserId): int
    {
        return 1;
    }

    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool
    {
        return true;
    }
}

final class CostUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
