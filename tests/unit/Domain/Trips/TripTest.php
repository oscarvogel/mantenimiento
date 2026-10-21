<?php

declare(strict_types=1);

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripStop;
use PHPUnit\Framework\TestCase;

final class TripTest extends TestCase
{
    public function testPlannedTripKeepsRouteAndAssignments(): void
    {
        $trip = $this->trip();

        self::assertTrue($trip->isPlanned());
        self::assertSame(Trip::PLANIFICADO, $trip->status());
        self::assertSame(7, $trip->truckId());
        self::assertSame(9, $trip->trailerId());
        self::assertSame([11, 12], $trip->driverIds());
        self::assertCount(2, $trip->stops());
        self::assertNull($trip->actualDeparture());
        self::assertNull($trip->departureKm());
    }

    public function testPlannedReturnMustBeAfterDeparture(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('regreso planificado');

        $this->trip(
            departure: new DateTimeImmutable('2026-10-20 08:00:00'),
            returnAt: new DateTimeImmutable('2026-10-20 08:00:00'),
        );
    }

    public function testExactlyOneTractorIsRequired(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tractor o camión');

        $this->trip(assignments: [
            $this->assignment(TripAssignment::CHOFER, 11),
        ]);
    }

    public function testSameEquipmentCannotFillTwoRoles(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dos roles');

        $this->trip(assignments: [
            $this->assignment(TripAssignment::TRACTOR, 7),
            $this->assignment(TripAssignment::ACOPLADO, 7),
            $this->assignment(TripAssignment::CHOFER, 11),
        ]);
    }

    public function testSameDriverCannotBeAssignedTwice(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('chofer');

        $this->trip(assignments: [
            $this->assignment(TripAssignment::TRACTOR, 7),
            $this->assignment(TripAssignment::CHOFER, 11),
            $this->assignment(TripAssignment::CHOFER, 11),
        ]);
    }

    public function testRouteNeedsAtLeastTwoStops(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('salida y una llegada');

        $this->trip(stops: [
            new TripStop(1, TripStop::CARGA, 'Rosario'),
        ]);
    }

    public function testStopsMustBeConsecutiveFromOne(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('consecutiva');

        $this->trip(stops: [
            new TripStop(1, TripStop::CARGA, 'Rosario'),
            new TripStop(3, TripStop::DESCARGA, 'Córdoba'),
        ]);
    }

    public function testPlannedTripCanKeepReferenceDepartureKmAndDriverPercentage(): void
    {
        $trip = Trip::plan(
            5,
            10,
            42,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            [
                $this->assignment(TripAssignment::TRACTOR, 7),
                new TripAssignment(
                    TripAssignment::CHOFER,
                    11,
                    new DateTimeImmutable('2026-10-20 08:00:00'),
                    new DateTimeImmutable('2026-10-21 18:00:00'),
                    null,
                    14.0,
                ),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Rosario'),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba'),
            ],
            '',
            145_300,
        );

        self::assertSame(145_300, $trip->departureKm());
        self::assertSame(14.0, $trip->assignments()[1]->liquidationPercentage());
    }

    public function testDriverPercentageMustBeBetweenZeroAndHundred(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('porcentaje');

        new TripAssignment(
            TripAssignment::CHOFER,
            11,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            null,
            null,
            101.0,
        );
    }

    public function testStartingTheTripRecordsRealDepartureAndOdometer(): void
    {
        $trip = $this->trip();

        $trip->start(new DateTimeImmutable('2026-10-20 08:15:00'), 145_300);

        self::assertSame(Trip::EN_CURSO, $trip->status());
        self::assertSame('2026-10-20 08:15:00', $trip->actualDeparture()?->format('Y-m-d H:i:s'));
        self::assertSame(145_300, $trip->departureKm());
    }

    public function testStartedTripCannotStartAgain(): void
    {
        $trip = $this->trip();
        $trip->start(new DateTimeImmutable('2026-10-20 08:15:00'), 145_300);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('planificado');

        $trip->start(new DateTimeImmutable('2026-10-20 08:30:00'), 145_310);
    }

    public function testCancellationRequiresReason(): void
    {
        $trip = $this->trip();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('motivo');

        $trip->cancel('   ');
    }

    public function testCancelledTripCannotStart(): void
    {
        $trip = $this->trip();
        $trip->cancel('El cliente suspendió la carga.');

        self::assertSame(Trip::CANCELADO, $trip->status());
        self::assertSame('El cliente suspendió la carga.', $trip->cancellationReason());

        $this->expectException(DomainException::class);
        $trip->start(new DateTimeImmutable('2026-10-20 08:15:00'), 145_300);
    }

    public function testStopRejectsNegativeQuantity(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cantidad');

        new TripStop(1, TripStop::CARGA, 'Rosario', quantity: '-5');
    }

    /**
     * @param list<TripAssignment>|null $assignments
     * @param list<TripStop>|null $stops
     */
    private function trip(
        ?DateTimeImmutable $departure = null,
        ?DateTimeImmutable $returnAt = null,
        ?array $assignments = null,
        ?array $stops = null,
    ): Trip {
        return Trip::plan(
            5,
            10,
            42,
            $departure ?? new DateTimeImmutable('2026-10-20 08:00:00'),
            $returnAt ?? new DateTimeImmutable('2026-10-21 18:00:00'),
            $assignments ?? [
                $this->assignment(TripAssignment::TRACTOR, 7),
                $this->assignment(TripAssignment::ACOPLADO, 9),
                $this->assignment(TripAssignment::CHOFER, 11),
                $this->assignment(TripAssignment::CHOFER, 12),
            ],
            $stops ?? [
                new TripStop(1, TripStop::CARGA, 'Rosario', customerId: 3, cargoDescription: 'Soja', quantity: '30', loadUnitId: 2),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba', customerId: 4),
            ],
            'Carga programada.',
        );
    }

    private function assignment(string $role, int $id): TripAssignment
    {
        return new TripAssignment($role, $id, new DateTimeImmutable('2026-10-20 08:00:00'), new DateTimeImmutable('2026-10-21 18:00:00'));
    }
}
