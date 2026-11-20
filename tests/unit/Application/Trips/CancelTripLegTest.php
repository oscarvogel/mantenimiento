<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\CancelTripLeg;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripLegCancellationStore;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripLeg;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class CancelTripLegTest extends TestCase
{
    public function testCancelsTheLegAndRecomputesThePlannedReturnFromTheLiveLegs(): void
    {
        $trips = new CancelLegTripRepositoryFake(Trip::EN_CURSO);
        $store = new CancelLegStoreFake(['cancelled' => false, 'assigned_costs' => 0]);
        $this->handler($trips, $store)->execute($this->actor(), 55, 9, '  Cargado dos veces ');

        self::assertSame([5, 10, 55, 9, 'Cargado dos veces', 1], $store->cancelled);
        self::assertSame('2026-11-18', $trips->plannedReturn?->format('Y-m-d'));
    }

    public function testRejectsLegsOfAnotherTrip(): void
    {
        $store = new CancelLegStoreFake(null);

        $this->expectExceptionMessage('no pertenece a este viaje');
        $this->handler(new CancelLegTripRepositoryFake(Trip::EN_CURSO), $store)->execute($this->actor(), 55, 9, 'Error');
    }

    public function testRejectsLegsOfAFinishedTripWithoutTouchingThem(): void
    {
        $store = new CancelLegStoreFake(['cancelled' => false, 'assigned_costs' => 0]);
        try {
            $this->handler(new CancelLegTripRepositoryFake(Trip::FINALIZADO), $store)->execute($this->actor(), 55, 9, 'Error');
            self::fail('Debió rechazar el viaje finalizado.');
        } catch (DomainException) {
        }

        self::assertNull($store->cancelled);
    }

    public function testRequiresPermissionToOperateTrips(): void
    {
        $store = new CancelLegStoreFake(['cancelled' => false, 'assigned_costs' => 0]);

        $this->expectExceptionMessage('No tenés permiso');
        $this->handler(new CancelLegTripRepositoryFake(Trip::EN_CURSO), $store)
            ->execute(new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]), 55, 9, 'Error');
    }

    private function handler(CancelLegTripRepositoryFake $trips, CancelLegStoreFake $store): CancelTripLeg
    {
        return new CancelTripLeg($trips, new CancelLegLegsFake(), $store, new CancelLegUnitOfWorkFake());
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], ['viajes.operar'], [10]);
    }
}

final class CancelLegTripRepositoryFake implements OperationalTripRepository
{
    public Trip $trip;
    public ?DateTimeImmutable $plannedReturn = null;

    public function __construct(string $status)
    {
        $started = $status !== Trip::PLANIFICADO;
        $this->trip = Trip::reconstitute(
            55, 5, 10, 30, $status,
            new DateTimeImmutable('2026-11-17 00:00:00'),
            new DateTimeImmutable('2026-11-20 00:00:00'),
            $started ? new DateTimeImmutable('2026-11-17 00:00:00') : null,
            $status === Trip::FINALIZADO ? new DateTimeImmutable('2026-11-20 00:00:00') : null,
            $started ? 878_868 : null,
            $status === Trip::FINALIZADO ? 880_000 : null,
            [
                new TripAssignment(TripAssignment::TRACTOR, 7, new DateTimeImmutable('2026-11-17 00:00:00')),
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-11-17 00:00:00')),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Mendoza'),
                new TripStop(2, TripStop::DESCARGA, 'Dionisio Cerqueira'),
            ],
            '',
        );
    }

    public function nextNumber(int $companyId): int { return 1; }
    public function add(Trip $trip, int $actorUserId): int { return 1; }
    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip { return $this->trip; }
    public function save(Trip $trip, int $actorUserId): void {}
    public function updateDetails(Trip $trip, int $actorUserId): void {}
    public function updateDriverPercentage(int $companyId, int $tripId, int $employeeId, float $percentage, int $actorUserId): void {}
    public function conflicts(int $companyId, array $equipmentIds, array $employeeIds, DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $excludeTripId = null): array { return []; }
    public function updatePlannedReturn(int $companyId, int $tripId, ?DateTimeImmutable $returnAt, int $actorUserId): void
    {
        $this->plannedReturn = $returnAt;
    }
}

final class CancelLegLegsFake implements TripLegRepository
{
    public function add(int $companyId, int $branchId, int $tripId, TripLeg $leg, int $actorUserId): int { return 1; }
    public function update(int $companyId, int $branchId, int $tripId, int $legId, TripLeg $leg, int $actorUserId): void {}
    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool { return true; }
    public function ordersWithMissingAmounts(int $companyId, int $tripId): array { return []; }
    public function lastLegEndDate(int $companyId, int $tripId): ?DateTimeImmutable { return new DateTimeImmutable('2026-11-18'); }
}

final class CancelLegStoreFake implements TripLegCancellationStore
{
    /** @var list<mixed>|null */
    public ?array $cancelled = null;

    /** @param array{cancelled:bool,assigned_costs:int}|null $state */
    public function __construct(private readonly ?array $state)
    {
    }

    public function state(int $companyId, int $tripId, int $legId): ?array { return $this->state; }

    public function cancel(int $companyId, int $branchId, int $tripId, int $legId, string $reason, int $actorUserId): void
    {
        $this->cancelled = [$companyId, $branchId, $tripId, $legId, $reason, $actorUserId];
    }
}

final class CancelLegUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
