<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\AddTripLegCommand;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripLocalityLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Application\Trips\UpdateTripLeg;
use App\Application\Trips\UpdateTripLegCommand;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripLeg;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class UpdateTripLegTest extends TestCase
{
    public function testUpdatesLegAmountsWhileTripIsOpen(): void
    {
        $legs = new UpdateLegRepositoryFake();
        $handler = new UpdateTripLeg(
            new UpdateLegTripRepositoryFake(Trip::EN_CURSO),
            $legs,
            new UpdateLegDirectoryFake(),
            new UpdateLegLocalityFake(),
            new UpdateLegUnitOfWorkFake(),
        );

        $handler->execute($this->actor(), new UpdateTripLegCommand(9, new AddTripLegCommand(
            55,
            'Rosario',
            'Córdoba',
            null,
            null,
            320,
            3,
            'ARS',
            1.0,
            'Carga de soja',
            947,
            30,
            1,
            2,
        )));

        self::assertNotNull($legs->updated);
        self::assertSame(9, $legs->legId);
        self::assertNull($legs->updated->invoice()->amount());
        self::assertNull($legs->updated->liquidation()->amount());
        self::assertSame('Rosario', $legs->updated->origin());
    }

    public function testUpdatesTheLiquidationInPesosWhileTheInvoiceIsInDollars(): void
    {
        $legs = new UpdateLegRepositoryFake();
        $handler = new UpdateTripLeg(
            new UpdateLegTripRepositoryFake(Trip::EN_CURSO),
            $legs,
            new UpdateLegDirectoryFake(),
            new UpdateLegLocalityFake(),
            new UpdateLegUnitOfWorkFake(),
        );

        $handler->execute($this->actor(), new UpdateTripLegCommand(9, new AddTripLegCommand(
            55,
            'Rosario',
            'Córdoba',
            4_700,
            5_875_000,
            320,
            3,
            'USD',
            1_250.0,
            'Carga de soja',
            947,
            30,
            1,
            2,
            null,
            null,
            'ARS',
        )));

        self::assertNotNull($legs->updated);
        self::assertSame('USD', $legs->updated->invoice()->currency());
        self::assertSame(5_875_000.0, $legs->updated->invoice()->amountArs());
        self::assertSame('ARS', $legs->updated->liquidation()->currency());
        self::assertSame(5_875_000.0, $legs->updated->liquidation()->amountArs());
    }

    public function testRejectsClosedTrip(): void
    {
        $handler = new UpdateTripLeg(
            new UpdateLegTripRepositoryFake(Trip::FINALIZADO),
            new UpdateLegRepositoryFake(),
            new UpdateLegDirectoryFake(),
            new UpdateLegLocalityFake(),
            new UpdateLegUnitOfWorkFake(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cerrado o cancelado');

        $handler->execute($this->actor(), new UpdateTripLegCommand(9, new AddTripLegCommand(55, 'Rosario', 'Córdoba', 1, 1, 0, 3, 'ARS', 1, null, 947, null, 1, 2)));
    }

    public function testRejectsLegFromAnotherTrip(): void
    {
        $legs = new UpdateLegRepositoryFake();
        $legs->belongs = false;
        $handler = new UpdateTripLeg(
            new UpdateLegTripRepositoryFake(Trip::EN_CURSO),
            $legs,
            new UpdateLegDirectoryFake(),
            new UpdateLegLocalityFake(),
            new UpdateLegUnitOfWorkFake(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no pertenece');

        $handler->execute($this->actor(), new UpdateTripLegCommand(9, new AddTripLegCommand(55, 'Rosario', 'Córdoba', 1, 1, 0, 3, 'ARS', 1, null, 947, null, 1, 2)));
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], ['viajes.operar'], [10]);
    }
}

final class UpdateLegTripRepositoryFake implements OperationalTripRepository
{
    public Trip $trip;

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
    public function updateDetails(Trip $trip, int $actorUserId): void {}
    public function updateDriverPercentage(int $companyId, int $tripId, int $employeeId, float $percentage, int $actorUserId): void {}
    public function conflicts(int $companyId, array $equipmentIds, array $employeeIds, DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $excludeTripId = null): array { return []; }
    public function updatePlannedReturn(int $companyId, int $tripId, ?DateTimeImmutable $returnAt, int $actorUserId): void {}
}

final class UpdateLegRepositoryFake implements TripLegRepository
{
    public ?TripLeg $updated = null;
    public int $legId = 0;
    public bool $belongs = true;

    public function add(int $companyId, int $branchId, int $tripId, TripLeg $leg, int $actorUserId): int { return 1; }
    public function update(int $companyId, int $branchId, int $tripId, int $legId, TripLeg $leg, int $actorUserId): void
    {
        $this->legId = $legId;
        $this->updated = $leg;
    }
    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool { return $this->belongs; }
    public function ordersWithMissingAmounts(int $companyId, int $tripId): array { return []; }
    public function lastLegEndDate(int $companyId, int $tripId): ?DateTimeImmutable { return null; }
}

final class UpdateLegDirectoryFake implements TripDirectoryLookup
{
    public function customerExists(int $companyId, int $customerId): bool { return true; }
    public function loadUnitExists(int $companyId, int $loadUnitId): bool { return true; }
    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool { return true; }
    public function expenseCategoryExists(int $companyId, int $categoryId): bool { return true; }
    public function paymentMethodExists(int $companyId, int $paymentMethodId): bool { return true; }
    public function concept(int $companyId, int $conceptId): ?array { return null; }
    public function currencyExists(string $code): bool { return true; }
}

final class UpdateLegLocalityFake implements TripLocalityLookup
{
    public function search(string $query, int $limit = 30): array { return []; }
    public function find(int $localityId): ?array { return ['id' => $localityId, 'name' => match ($localityId) { 1 => 'Rosario', 2 => 'Córdoba', default => 'Localidad ' . $localityId }, 'label' => 'Localidad QA']; }
    public function provinces(): array { return []; }
}

final class UpdateLegUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
