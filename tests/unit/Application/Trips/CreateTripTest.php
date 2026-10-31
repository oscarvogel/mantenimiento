<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\CreateTrip;
use App\Application\Trips\CreateTripCommand;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripLocalityLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\TripConflict;
use App\Application\Trips\TripLegInput;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use PHPUnit\Framework\TestCase;

final class CreateTripTest extends TestCase
{
    public function testCreatesTripAlreadyInProgressInsideActorCompanyAndReturnsNumber(): void
    {
        $repository = new CreateTripRepositoryFake();
        $legs = new CreateTripLegsFake();
        $handler = new CreateTrip($repository, $legs, $this->fleet(), $this->directory(), new CreateTripLocalityFake(), new CreateTripUnitOfWorkFake());

        $result = $handler->execute($this->actor(['viajes.operar'], [10]), $this->command());

        self::assertSame(42, $result->number);
        self::assertSame(100, $result->id);
        self::assertNotNull($repository->added);
        self::assertSame(5, $repository->added->companyId());
        self::assertSame(10, $repository->added->branchId());
        self::assertSame(7, $repository->added->truckId());
        self::assertSame(9, $repository->added->trailerId());
        self::assertSame([11, 12], $repository->added->driverIds());
        self::assertSame(145_300, $repository->added->departureKm());
        self::assertSame(14.0, $repository->added->assignments()[2]->liquidationPercentage());
        self::assertNull($repository->added->assignments()[3]->liquidationPercentage());
        self::assertCount(0, $repository->added->stops());
        self::assertCount(2, $legs->added);
        self::assertSame(947, $legs->added[0]['leg']->originDestinationCode());
        self::assertSame(324, $legs->added[0]['leg']->customerId());
        self::assertNull($legs->added[0]['leg']->kilometers());
        self::assertSame(Trip::EN_CURSO, $repository->added->status());
        self::assertEquals(new DateTimeImmutable('2026-10-20 08:00:00'), $repository->added->actualDeparture());
        self::assertSame([7, 9], $repository->queriedEquipmentIds);
        self::assertSame([11, 12], $repository->queriedEmployeeIds);
    }

    public function testRejectsActorWithoutOperatePermission(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, new CreateTripLegsFake(), $this->fleet(), $this->directory(), new CreateTripLocalityFake(), new CreateTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('permiso');

        $handler->execute($this->actor(['viajes.ver'], [10]), $this->command());
    }

    public function testRejectsBranchOutsideActorScope(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, new CreateTripLegsFake(), $this->fleet(), $this->directory(), new CreateTripLocalityFake(), new CreateTripUnitOfWorkFake());

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
        $handler = new CreateTrip($repository, new CreateTripLegsFake(), $this->fleet(), $this->directory(), new CreateTripLocalityFake(), new CreateTripUnitOfWorkFake());

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
        $handler = new CreateTrip($repository, new CreateTripLegsFake(), $fleet, $this->directory(), new CreateTripLocalityFake(), new CreateTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('acoplado');

        $handler->execute($this->actor(['viajes.operar'], [10]), $this->command());
    }

    public function testRejectsCustomerThatDoesNotBelongToTheCompany(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, new CreateTripLegsFake(), $this->fleet(), $this->directory(false), new CreateTripLocalityFake(), new CreateTripUnitOfWorkFake());
        $command = new CreateTripCommand(
            10,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            7,
            [9],
            [11],
            [
                new TripLegInput(947, 'Rosario', 'Córdoba', 999, 1000, 800),
            ],
            145_300,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('clientes');

        $handler->execute($this->actor(['viajes.operar'], [10]), $command);
    }

    public function testCommandReadsNestedStopsAndDrivers(): void
    {
        $command = CreateTripCommand::fromInput([
            'sucursal_id' => '10',
            'fecha_salida_real' => '2026-10-20T08:00',
            'fecha_regreso_estimado' => '2026-10-21T18:30',
            'tractor_id' => '7',
            'acoplado_id' => '9',
            'choferes' => ['11', '12'],
            'choferes_porcentajes' => ['11' => '14,5', '12' => ''],
            'km_salida' => '145300',
            'tramos' => [
                ['codigo_origen_destino' => '947', 'origen' => 'Rosario', 'destino' => 'Córdoba', 'localidad_origen_id' => '1', 'localidad_destino_id' => '2', 'cliente_id' => '324', 'flete_facturacion' => '3850000', 'flete_liquidacion' => '2500000', 'km' => '', 'toneladas' => '30,5'],
                ['codigo_origen_destino' => '154', 'origen' => 'Zárate', 'destino' => 'Córdoba', 'localidad_origen_id' => '3', 'localidad_destino_id' => '2', 'cliente_id' => '4', 'flete_facturacion' => '1291784,41', 'flete_liquidacion' => '900000'],
            ],
            'observaciones' => ' Salida temprano. ',
        ]);

        self::assertSame(10, $command->branchId);
        self::assertSame([11, 12], $command->driverIds);
        self::assertSame(145_300, $command->departureKm);
        self::assertSame(14.5, $command->driverPercentages[11]);
        self::assertNull($command->driverPercentages[12]);
        self::assertCount(2, $command->legs);
        self::assertSame(947, $command->legs[0]->originDestinationCode);
        self::assertSame(324, $command->legs[0]->customerId);
        self::assertNull($command->legs[0]->kilometers);
        self::assertSame(30.5, $command->legs[0]->tonnes);
        self::assertSame(3_850_000.0, $command->legs[0]->invoiceAmount);
        self::assertSame(2_500_000.0, $command->legs[0]->liquidationAmount);
        self::assertSame('Salida temprano.', $command->notes);
    }

    public function testCommandRequiresTheActualDepartureOdometer(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('odómetro real de salida');

        CreateTripCommand::fromInput([
            'sucursal_id' => '10',
            'fecha_salida_real' => '2026-10-20T08:00',
            'fecha_regreso_estimado' => '2026-10-21T18:30',
            'tractor_id' => '7',
            'choferes' => ['11'],
            'tramos' => [
                ['codigo_origen_destino' => '947', 'origen' => 'Rosario', 'destino' => 'Córdoba', 'localidad_origen_id' => '1', 'localidad_destino_id' => '2', 'cliente_id' => '324', 'flete_facturacion' => '3850000', 'flete_liquidacion' => '2500000'],
            ],
        ]);
    }

    public function testRegistraLaComposicionCompletaDeUnBitren(): void
    {
        $repository = new CreateTripRepositoryFake();
        $handler = new CreateTrip($repository, new CreateTripLegsFake(), $this->fleet(), $this->directory(), new CreateTripLocalityFake(), new CreateTripUnitOfWorkFake());
        $command = new CreateTripCommand(
            10,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            7,
            [9, 10],
            [11],
            [
                new TripLegInput(947, 'Rosario', 'Córdoba', 324, 3_850_000, 2_500_000, null, 30, 'ARS', 1, null, 1, 2),
                new TripLegInput(154, 'Zárate', 'Córdoba', 4, 1_291_784.41, 900_000, null, null, 'ARS', 1, null, 3, 2),
            ],
            145_300,
        );

        $handler->execute($this->actor(['viajes.operar'], [10]), $command);

        $trip = $repository->added;
        self::assertNotNull($trip);
        self::assertSame([9, 10], $trip->trailerIds());
        self::assertSame(9, $trip->trailerId());
        self::assertSame([7, 9, 10], $repository->queriedEquipmentIds);
    }

    public function testElComandoLeeLaListaDeSemirremolques(): void
    {
        $command = CreateTripCommand::fromInput([
            'sucursal_id' => '10',
            'fecha_salida_real' => '2026-10-20T08:00',
            'fecha_regreso_estimado' => '2026-10-21T18:30',
            'km_salida' => '145300',
            'tractor_id' => '7',
            'acoplado_id' => ['9', '10'],
            'choferes' => ['11'],
            'tramos' => [
                ['codigo_origen_destino' => '947', 'origen' => 'Rosario', 'destino' => 'Córdoba', 'localidad_origen_id' => '1', 'localidad_destino_id' => '2', 'cliente_id' => '324', 'flete_facturacion' => '3850000', 'flete_liquidacion' => '2500000'],
            ],
        ]);

        self::assertSame([9, 10], $command->trailerIds);
    }

    public function testElComandoAceptaUnSemirremolqueSuelto(): void
    {
        $command = CreateTripCommand::fromInput([
            'sucursal_id' => '10',
            'fecha_salida_real' => '2026-10-20T08:00',
            'fecha_regreso_estimado' => '2026-10-21T18:30',
            'km_salida' => '145300',
            'tractor_id' => '7',
            'acoplado_id' => '9',
            'choferes' => ['11'],
            'tramos' => [
                ['codigo_origen_destino' => '947', 'origen' => 'Rosario', 'destino' => 'Córdoba', 'localidad_origen_id' => '1', 'localidad_destino_id' => '2', 'cliente_id' => '324', 'flete_facturacion' => '3850000', 'flete_liquidacion' => '2500000'],
            ],
        ]);

        self::assertSame([9], $command->trailerIds);
    }

    public function testRejectsMoreThanThreeTrailers(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bitrén o tridem');

        CreateTripCommand::fromInput([
            'sucursal_id' => '10',
            'fecha_salida_real' => '2026-10-20T08:00',
            'fecha_regreso_estimado' => '2026-10-21T18:30',
            'km_salida' => '145300',
            'tractor_id' => '7',
            'acoplado_id' => ['9', '10', '11', '12'],
            'choferes' => ['11'],
            'tramos' => [
                ['codigo_origen_destino' => '947', 'origen' => 'Rosario', 'destino' => 'Córdoba', 'localidad_origen_id' => '1', 'localidad_destino_id' => '2', 'cliente_id' => '324', 'flete_facturacion' => '3850000', 'flete_liquidacion' => '2500000'],
            ],
        ]);
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
            [9],
            [11, 12],
            [
                new TripLegInput(947, 'Rosario', 'Córdoba', 324, 3_850_000, 2_500_000, null, 30, 'ARS', 1, null, 1, 2),
                new TripLegInput(154, 'Zárate', 'Córdoba', 4, 1_291_784.41, 900_000, null, null, 'ARS', 1, null, 3, 2),
            ],
            145_300,
            'Carga programada.',
            [11 => 14.0, 12 => null],
        );
    }

    private function directory(bool $customersExist = true): TripDirectoryLookup
    {
        return new class($customersExist) implements TripDirectoryLookup {
            public function __construct(private bool $customersExist) {}
            public function customerExists(int $companyId, int $customerId): bool
            {
                return $this->customersExist;
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
                    10 => ['id' => 10, 'branchId' => 10, 'label' => 'AD 333 AD', 'trailer' => true, 'tractor' => false],
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
        ?DateTimeImmutable $to,
        ?int $excludeTripId = null,
    ): array {
        $this->queriedEquipmentIds = $equipmentIds;
        $this->queriedEmployeeIds = $employeeIds;

        return $this->conflicts;
    }
}

final class CreateTripLegsFake implements TripLegRepository
{
    /** @var list<array{companyId:int,branchId:int,tripId:int,leg:\App\Domain\Trips\TripLeg,actorUserId:int}> */
    public array $added = [];

    public function add(int $companyId, int $branchId, int $tripId, \App\Domain\Trips\TripLeg $leg, int $actorUserId): int
    {
        $this->added[] = compact('companyId', 'branchId', 'tripId', 'leg', 'actorUserId');
        return count($this->added);
    }

    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool { return true; }
}

final class CreateTripLocalityFake implements TripLocalityLookup
{
    public function search(string $query, int $limit = 30): array { return []; }

    public function find(int $localityId): ?array
    {
        return ['id' => $localityId, 'name' => match ($localityId) { 1 => 'Rosario', 2 => 'Córdoba', 3 => 'Zárate', default => 'Localidad ' . $localityId }, 'label' => 'Localidad QA'];
    }
}

final class CreateTripUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
