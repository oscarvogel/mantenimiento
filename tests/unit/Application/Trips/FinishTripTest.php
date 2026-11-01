<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\FinishTrip;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripClock;
use App\Application\Trips\Port\TripReadingRecorder;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class FinishTripTest extends TestCase
{
    public function testClosesTripAndRecordsReading(): void
    {
        $trips = new FinishTripRepositoryFake();
        $readings = new FinishTripReadingFake();
        $handler = new FinishTrip($trips, $readings, new FinishTripUnitOfWorkFake(), new FinishTripClockFake());

        $at = new DateTimeImmutable('2026-10-21 18:30:00');
        $handler->execute($this->actor(['viajes.operar']), 55, $at, 145_700);

        self::assertSame(Trip::FINALIZADO, $trips->trip->status());
        self::assertSame(145_700, $trips->trip->returnKm());
        self::assertSame(7, $readings->equipmentId);
        self::assertSame(145_700, $readings->kilometers);
        self::assertSame('2026-10-21 18:30:00', $readings->at?->format('Y-m-d H:i:s'));
        self::assertSame('Viaje #42', $readings->reference);
    }

    public function testRejectsPlannedTrip(): void
    {
        $trips = new FinishTripRepositoryFake(Trip::PLANIFICADO);
        $handler = new FinishTrip($trips, new FinishTripReadingFake(), new FinishTripUnitOfWorkFake(), new FinishTripClockFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('en curso');

        $handler->execute($this->actor(['viajes.operar']), 55, null, 145_700);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], $permissions, [10]);
    }
}

final class FinishTripRepositoryFake implements OperationalTripRepository
{
    public Trip $trip;

    public function __construct(string $status = Trip::EN_CURSO)
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
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-10-20 08:00:00')),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Rosario'),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba'),
            ],
            '',
        );
    }

    public function nextNumber(int $companyId): int { return 1; }
    public function add(Trip $trip, int $actorUserId): int { return 1; }
    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip { return $this->trip; }
    public function save(Trip $trip, int $actorUserId): void {}
    public function conflicts(int $companyId, array $equipmentIds, array $employeeIds, DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $excludeTripId = null): array { return []; }
}

final class FinishTripReadingFake implements TripReadingRecorder
{
    public ?int $equipmentId = null;
    public ?DateTimeImmutable $at = null;
    public ?int $kilometers = null;
    public ?string $reference = null;

    public function record(ActorContext $actor, int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void
    {
        $this->equipmentId = $equipmentId;
        $this->at = $at;
        $this->kilometers = $kilometers;
        $this->reference = $reference;
    }
}

final class FinishTripUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}

final class FinishTripClockFake implements TripClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-21 18:30:00');
    }
}
