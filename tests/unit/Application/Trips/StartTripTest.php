<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripClock;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\StartTrip;
use App\Application\Trips\TripConflict;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class StartTripTest extends TestCase
{
    public function testStartsScopedPlannedTripAndSavesOdometer(): void
    {
        $repository = new StartTripRepositoryFake($this->plannedTrip());
        $handler = new StartTrip($repository, new StartTripUnitOfWorkFake(), $this->clock());

        $handler->execute($this->actor(['viajes.operar'], [10]), 55, new DateTimeImmutable('2026-10-20 08:20:00'), 145_300);

        self::assertSame([5, [10], 55], $repository->findQuery);
        self::assertNotNull($repository->saved);
        self::assertSame(Trip::EN_CURSO, $repository->saved->status());
        self::assertSame(145_300, $repository->saved->departureKm());
        self::assertSame('2026-10-20 08:20:00', $repository->saved->actualDeparture()?->format('Y-m-d H:i:s'));
    }

    public function testUsesClockWhenDepartureIsNotProvided(): void
    {
        $repository = new StartTripRepositoryFake($this->plannedTrip());
        $handler = new StartTrip($repository, new StartTripUnitOfWorkFake(), $this->clock());

        $handler->execute($this->actor(['viajes.operar'], [10]), 55, null, 145_300);

        self::assertSame('2026-10-20 09:00:00', $repository->saved?->actualDeparture()?->format('Y-m-d H:i:s'));
    }

    public function testRejectsActorWithoutOperatePermission(): void
    {
        $repository = new StartTripRepositoryFake($this->plannedTrip());
        $handler = new StartTrip($repository, new StartTripUnitOfWorkFake(), $this->clock());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('permiso');

        $handler->execute($this->actor(['viajes.ver'], [10]), 55, null, 145_300);
    }

    public function testTreatsTripOutsideScopeAsNotFound(): void
    {
        $repository = new StartTripRepositoryFake(null);
        $handler = new StartTrip($repository, new StartTripUnitOfWorkFake(), $this->clock());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no existe');

        $handler->execute($this->actor(['viajes.operar'], [10]), 55, null, 145_300);
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], $permissions, $branches);
    }

    private function clock(): TripClock
    {
        return new class implements TripClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-20 09:00:00');
            }
        };
    }

    private function plannedTrip(): Trip
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

final class StartTripRepositoryFake implements OperationalTripRepository
{
    public array $findQuery = [];
    public ?Trip $saved = null;

    public function __construct(private ?Trip $trip)
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
        $this->findQuery = [$companyId, $branchIds, $tripId];

        return $this->trip;
    }

    public function save(Trip $trip, int $actorUserId): void
    {
        $this->saved = $trip;
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

final class StartTripUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
