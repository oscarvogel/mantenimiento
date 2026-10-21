<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\AddTripLeg;
use App\Application\Trips\AddTripLegCommand;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripLeg;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class AddTripLegTest extends TestCase
{
    public function testAddsLegInsideTripScope(): void
    {
        $trips = new AddLegTripRepositoryFake(Trip::PLANIFICADO);
        $legs = new AddLegRepositoryFake();
        $handler = new AddTripLeg($trips, $legs, new AddLegDirectoryFake(), new AddLegUnitOfWorkFake());

        $id = $handler->execute($this->actor(['viajes.operar']), new AddTripLegCommand(
            55,
            'Rosario',
            'Córdoba',
            1_000_000,
            800_000,
            320,
            3,
            'ARS',
            1.0,
            'Carga de soja',
        ));

        self::assertSame(1, $id);
        self::assertNotNull($legs->leg);
        self::assertSame('Rosario', $legs->leg->origin());
        self::assertSame(800_000.0, $legs->leg->liquidationAmount());
        self::assertSame(112_000.0, $legs->leg->commission(14.0));
        self::assertSame(55, $trips->trip->id());
    }

    public function testRejectsClosedTrip(): void
    {
        $handler = new AddTripLeg(
            new AddLegTripRepositoryFake(Trip::FINALIZADO),
            new AddLegRepositoryFake(),
            new AddLegDirectoryFake(),
            new AddLegUnitOfWorkFake(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cerrado');

        $handler->execute($this->actor(['viajes.operar']), new AddTripLegCommand(55, 'Rosario', 'Córdoba', 1, 1));
    }

    public function testCommandParsesAmountsAndCurrency(): void
    {
        $command = AddTripLegCommand::fromInput([
            'viaje_id' => '55',
            'origen' => ' Rosario ',
            'destino' => ' Córdoba ',
            'km' => '320,5',
            'flete_facturacion' => '1000000',
            'flete_liquidacion' => '800000',
            'moneda' => 'ars',
            'cotizacion' => '1',
            'cliente_id' => '3',
        ]);

        self::assertSame(55, $command->tripId);
        self::assertSame('Rosario', $command->origin);
        self::assertSame(1_000_000.0, $command->invoiceAmount);
        self::assertSame(320.5, $command->kilometers);
        self::assertSame('ARS', $command->currency);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], $permissions, [10]);
    }
}

final class AddLegTripRepositoryFake implements OperationalTripRepository
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

    public function nextNumber(int $companyId): int { return 1; }
    public function add(Trip $trip, int $actorUserId): int { return 1; }
    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip { return $this->trip; }
    public function save(Trip $trip, int $actorUserId): void {}
    public function conflicts(int $companyId, array $equipmentIds, array $employeeIds, DateTimeImmutable $from, DateTimeImmutable $to, ?int $excludeTripId = null): array { return []; }
}

final class AddLegRepositoryFake implements TripLegRepository
{
    public ?TripLeg $leg = null;

    public function add(Trip $trip, TripLeg $leg, int $actorUserId): int
    {
        $this->leg = $leg;

        return 1;
    }

    public function belongsToTrip(int $companyId, int $tripId, int $legId): bool
    {
        return true;
    }
}

final class AddLegDirectoryFake implements TripDirectoryLookup
{
    public function customerExists(int $companyId, int $customerId): bool { return true; }
    public function loadUnitExists(int $companyId, int $loadUnitId): bool { return true; }
    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool { return true; }
    public function expenseCategoryExists(int $companyId, int $categoryId): bool { return true; }
    public function paymentMethodExists(int $companyId, int $paymentMethodId): bool { return true; }
    public function currencyExists(string $code): bool { return true; }
}

final class AddLegUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
