<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\CancelTrip;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class CancelTripTest extends TestCase
{
    public function testCancelsPlannedTripWithReasonAndAuthorship(): void
    {
        $trip = $this->plannedTrip();
        $repository = new CancelTripRepositoryFake($trip);
        $handler = new CancelTrip($repository, new CancelTripUnitOfWorkFake());

        $handler->execute($this->actor(['viajes.operar'], [10]), 55, 'El cliente suspendió la carga.');

        self::assertSame([5, [10], 55], $repository->findQuery);
        self::assertNotNull($repository->saved);
        self::assertSame(Trip::CANCELADO, $repository->saved->status());
        self::assertSame('El cliente suspendió la carga.', $repository->saved->cancellationReason());
    }

    public function testRejectsActorWithoutOperatePermission(): void
    {
        $handler = new CancelTrip(new CancelTripRepositoryFake($this->plannedTrip()), new CancelTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('permiso');

        $handler->execute($this->actor(['viajes.ver'], [10]), 55, 'Motivo');
    }

    public function testTreatsTripOutsideScopeAsNotFound(): void
    {
        $handler = new CancelTrip(new CancelTripRepositoryFake(null), new CancelTripUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no existe');

        $handler->execute($this->actor(['viajes.operar'], [10]), 55, 'Motivo');
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], $permissions, $branches);
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

final class CancelTripRepositoryFake implements OperationalTripRepository
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

final class CancelTripUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
