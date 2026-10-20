<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\CreateTrip;
use App\Application\Trips\CreateTripCommand;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\TripConflict;
use App\Application\Trips\TripStopInput;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use PHPUnit\Framework\TestCase;

final class CreateTripTest extends TestCase
{
    public function testPlansTripInsideActorCompanyAndReturnsNumber(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, $this->fleet(), $this->directory(), new CreateTripUnitOfWorkFake());

        $result = $handler->execute($this->actor(['viajes.operar'], [10]), $this->command());

        self::assertSame(42, $result->number);
        self::assertSame(100, $result->id);
        self::assertNotNull($repository->added);
        self::assertSame(5, $repository->added->companyId());
        self::assertSame(10, $repository->added->branchId());
        self::assertSame(7, $repository->added->truckId());
        self::assertSame(9, $repository->added->trailerId());
        self::assertSame([11, 12], $repository->added->driverIds());
        self::assertCount(2, $repository->added->stops());
        self::assertSame([7, 9], $repository->queriedEquipmentIds);
        self::assertSame([11, 12], $repository->queriedEmployeeIds);
    }

    public function testRejectsActorWithoutOperatePermission(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, $this->fleet(), $this->directory(), new CreateTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('permiso');

        $handler->execute($this->actor(['viajes.ver'], [10]), $this->command());
    }

    public function testRejectsBranchOutsideActorScope(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, $this->fleet(), $this->directory(), new CreateTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sucursal');

        $handler->execute($this->actor(['viajes.operar'], [20]), $this->command());
    }

    public function testRejectsOverlappingEquipmentWithActionableMessage(): void
    {
        $repository = new CreateTripRepositoryFake();
        $repository->conflicts = [
            new TripConflict(
                77,
                12,
                Trip::PLANIFICADO,
                TripAssignment::TRACTOR,
                7,
                new DateTimeImmutable('2026-10-20 07:00:00'),
                new DateTimeImmutable('2026-10-20 20:00:00'),
            ),
        ];
        $handler = new CreateTrip($repository, $this->fleet(), $this->directory(), new CreateTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('viaje #12');

        $handler->execute($this->actor(['viajes.operar'], [10]), $this->command());
    }

    public function testRejectsTrailerChosenAsTruck(): void
    {
        $repository = new CreateTripRepositoryFake();
        $fleet = new class implements TripFleetLookup {
            public function equipment(int $companyId, int $equipmentId): ?array
            {
                return ['id' => $equipmentId, 'branchId' => 10, 'label' => 'AC 123', 'trailer' => true, 'tractor' => false];
            }

            public function driver(int $companyId, int $employeeId): ?array
            {
                return ['id' => $employeeId, 'label' => 'Chofer', 'active' => true];
            }
        };
        $handler = new CreateTrip($repository, $fleet, $this->directory(), new CreateTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('acoplado');

        $handler->execute($this->actor(['viajes.operar'], [10]), $this->command());
    }

    public function testRejectsAddressThatDoesNotBelongToTheSelectedCustomer(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, $this->fleet(), $this->directory(), new CreateTripUnitOfWorkFake());
        $command = new CreateTripCommand(
            10,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            7,
            9,
            [11],
            [
                new TripStopInput('CARGA', 'Rosario', 3, 99),
                new TripStopInput('DESCARGA', 'Córdoba'),
            ],
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('domicilio');

        $handler->execute($this->actor(['viajes.operar'], [10]), $command);
    }

    public function testCommandReadsNestedStopsAndDrivers(): void
    {
        $command = CreateTripCommand::fromInput([
            'sucursal_id' => '10',
            'fecha_salida_planificada' => '2026-10-20T08:00',
            'fecha_regreso_planificada' => '2026-10-21T18:30',
            'tractor_id' => '7',
            'acoplado_id' => '9',
            'choferes' => ['11', '12'],
            'paradas' => [
                ['tipo' => 'CARGA', 'ubicacion' => 'Rosario', 'cliente_id' => '3', 'cantidad' => '30,5', 'unidad_carga_id' => '2'],
                ['tipo' => 'DESCARGA', 'ubicacion' => 'Córdoba'],
            ],
            'observaciones' => ' Salida temprano. ',
        ]);

        self::assertSame(10, $command->branchId);
        self::assertSame([11, 12], $command->driverIds);
        self::assertCount(2, $command->stops);
        self::assertSame('30,5', $command->stops[0]->quantity);
        self::assertSame('Salida temprano.', $command->notes);
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], $permissions, $branches);
    }

    private function command(): CreateTripCommand
    {
        return new CreateTripCommand(
            10,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            7,
            9,
            [11, 12],
            [
                new TripStopInput('CARGA', 'Rosario', 3, null, null, 'Soja', '30', 2),
                new TripStopInput('DESCARGA', 'Córdoba', 4),
            ],
            'Carga programada.',
        );
    }

    private function directory(): TripDirectoryLookup
    {
        return new class implements TripDirectoryLookup {
            public function customerExists(int $companyId, int $customerId): bool
            {
                return true;
            }

            public function loadUnitExists(int $companyId, int $loadUnitId): bool
            {
                return true;
            }

            public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool
            {
                return $customerId === 3 && $addressId === 4;
            }

            public function expenseCategoryExists(int $companyId, int $categoryId): bool
            {
                return true;
            }

            public function paymentMethodExists(int $companyId, int $paymentMethodId): bool
            {
                return true;
            }

            public function currencyExists(string $code): bool
            {
                return true;
            }
        };
    }

    private function fleet(): TripFleetLookup
    {
        return new class implements TripFleetLookup {
            public function equipment(int $companyId, int $equipmentId): ?array
            {
                return match ($equipmentId) {
                    7 => ['id' => 7, 'branchId' => 10, 'label' => 'AA 111 AA', 'trailer' => false, 'tractor' => true],
                    9 => ['id' => 9, 'branchId' => 10, 'label' => 'AC 222 AC', 'trailer' => true, 'tractor' => false],
                    default => null,
                };
            }

            public function driver(int $companyId, int $employeeId): ?array
            {
                return ['id' => $employeeId, 'label' => 'Chofer ' . $employeeId, 'active' => true];
            }
        };
    }
}

final class CreateTripRepositoryFake implements OperationalTripRepository
{
    public ?Trip $added = null;
    /** @var list<TripConflict> */
    public array $conflicts = [];
    /** @var list<int> */
    public array $queriedEquipmentIds = [];
    /** @var list<int> */
    public array $queriedEmployeeIds = [];

    public function nextNumber(int $companyId): int
    {
        return 42;
    }

    public function add(Trip $trip, int $actorUserId): int
    {
        $this->added = $trip;

        return 100;
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip
    {
        return null;
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
        $this->queriedEquipmentIds = $equipmentIds;
        $this->queriedEmployeeIds = $employeeIds;

        return $this->conflicts;
    }
}

final class CreateTripUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
