<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\UpdateTrip;
use App\Application\Trips\UpdateTripCommand;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class UpdateTripTest extends TestCase
{
    public function testUpdatesOpenTripAssignmentsDatesAndNotes(): void
    {
        $trips = new UpdateTripRepositoryFake(Trip::EN_CURSO);
        $handler = new UpdateTrip($trips, new UpdateTripFleetFake(), new UpdateTripUnitOfWorkFake());

        $handler->execute($this->actor(), 55, new UpdateTripCommand(
            new DateTimeImmutable('2026-10-22 00:00:00'),
            new DateTimeImmutable('2026-10-24 00:00:00'),
            7,
            [9],
            [11],
            146_100,
            'Corrección de salida.',
            [11 => 15.0],
        ));

        self::assertNotNull($trips->updated);
        self::assertSame('2026-10-22 00:00:00', $trips->updated->actualDeparture()?->format('Y-m-d H:i:s'));
        self::assertSame(146_100, $trips->updated->departureKm());
        self::assertSame([9], $trips->updated->trailerIds());
        self::assertSame([11], $trips->updated->driverIds());
        self::assertSame(15.0, $trips->updated->assignments()[2]->liquidationPercentage());
        self::assertSame('Corrección de salida.', $trips->updated->notes());
        self::assertSame(55, $trips->excludeTripId);
    }

    public function testRejectsClosedTrip(): void
    {
        $handler = new UpdateTrip(new UpdateTripRepositoryFake(Trip::FINALIZADO), new UpdateTripFleetFake(), new UpdateTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('planificado o en curso');

        $handler->execute($this->actor(), 55, $this->command());
    }

    public function testCommandAcceptsOptionalEstimatedReturn(): void
    {
        $command = UpdateTripCommand::fromInput([
            'fecha_salida_real' => '2026-10-22',
            'fecha_regreso_estimado' => '',
            'km_salida' => '146100',
            'tractor_id' => '7',
            'acoplado_id' => ['9'],
            'choferes' => ['11'],
            'choferes_porcentajes' => ['11' => '15,5'],
            'observaciones' => ' Corrección ',
        ]);

        self::assertNull($command->estimatedReturnAt);
        self::assertSame(146_100, $command->departureKm);
        self::assertSame([9], $command->trailerIds);
        self::assertSame(15.5, $command->driverPercentages[11]);
        self::assertSame('Corrección', $command->notes);
    }

    private function command(): UpdateTripCommand
    {
        return new UpdateTripCommand(
            new DateTimeImmutable('2026-10-22 00:00:00'),
            null,
            7,
            [9],
            [11],
            146_100,
        );
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], ['viajes.operar'], [10]);
    }
}

final class UpdateTripRepositoryFake implements OperationalTripRepository
{
    public Trip $trip;
    public ?Trip $updated = null;
    public ?int $excludeTripId = null;

    public function __construct(string $status)
    {
        $this->trip = Trip::reconstitute(
            55,
            5,
            10,
            42,
            $status,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            $status === Trip::EN_CURSO ? new DateTimeImmutable('2026-10-20 08:00:00') : null,
            null,
            $status === Trip::EN_CURSO ? 145_300 : null,
            null,
            [
                new TripAssignment(TripAssignment::TRACTOR, 7, new DateTimeImmutable('2026-10-20 08:00:00')),
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-10-20 08:00:00'), null, null, 14.0),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Rosario'),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba'),
            ],
            'Original',
        );
    }

    public function nextNumber(int $companyId): int { return 1; }
    public function add(Trip $trip, int $actorUserId): int { return 1; }
    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip { return $this->trip; }
    public function save(Trip $trip, int $actorUserId): void {}
    public function updateDetails(Trip $trip, int $actorUserId): void { $this->updated = $trip; }
    public function updateDriverPercentage(int $companyId, int $tripId, int $employeeId, float $percentage, int $actorUserId): void {}
    public function conflicts(int $companyId, array $equipmentIds, array $employeeIds, DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $excludeTripId = null): array
    {
        $this->excludeTripId = $excludeTripId;

        return [];
    }
}

final class UpdateTripFleetFake implements TripFleetLookup
{
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
}

final class UpdateTripUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
