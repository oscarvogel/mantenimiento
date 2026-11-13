<?php

declare(strict_types=1);

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use PHPUnit\Framework\TestCase;

final class TripPlannedReturnTest extends TestCase
{
    public function testElRegresoEstimadoSeCompletaConLaFechaDelUltimoTramo(): void
    {
        $trip = $this->trip(Trip::EN_CURSO);

        $trip->syncPlannedReturn(new DateTimeImmutable('2026-10-21 00:00:00'));

        self::assertSame('2026-10-21 00:00:00', $trip->plannedReturn()?->format('Y-m-d H:i:s'));
    }

    public function testUnRegresoAnteriorALaSalidaSeIgnora(): void
    {
        $trip = $this->trip(Trip::EN_CURSO);

        $trip->syncPlannedReturn(new DateTimeImmutable('2026-10-19 00:00:00'));

        self::assertNull($trip->plannedReturn());
    }

    public function testSinFechaDeFinElRegresoEstimadoQuedaVacio(): void
    {
        $trip = $this->trip(Trip::EN_CURSO);

        $trip->syncPlannedReturn(null);

        self::assertNull($trip->plannedReturn());
    }

    public function testUnViajeCerradoNoCambiaSuRegresoEstimado(): void
    {
        $trip = $this->trip(Trip::FINALIZADO);

        $trip->syncPlannedReturn(new DateTimeImmutable('2026-10-25 00:00:00'));

        self::assertNull($trip->plannedReturn());
    }

    private function trip(string $status): Trip
    {
        return Trip::reconstitute(
            55,
            5,
            10,
            42,
            $status,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            null,
            $status === Trip::PLANIFICADO ? null : new DateTimeImmutable('2026-10-20 08:00:00'),
            null,
            null,
            null,
            [
                new TripAssignment(TripAssignment::TRACTOR, 7, new DateTimeImmutable('2026-10-20 08:00:00')),
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-10-20 08:00:00')),
            ],
            [],
            '',
        );
    }
}
