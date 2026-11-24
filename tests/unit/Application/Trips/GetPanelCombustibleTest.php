<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\FuelPanelFilters;
use App\Application\Trips\GetPanelCombustible;
use App\Application\Trips\Port\FuelPanelReader;
use App\Application\Trips\Port\TripClock;
use PHPUnit\Framework\TestCase;

/**
 * El panel de combustible es la lectura del módulo sobre la tabla de cargas
 * del viaje: tiene que separar lo declarado de lo que falta y decir por qué.
 */
final class GetPanelCombustibleTest extends TestCase
{
    public function testTotalsAndReasonForTripsWithoutData(): void
    {
        $reader = new FuelPanelReaderFake([
            $this->row(1, 21, 'FINALIZADO', '2026-10-30', '2026-11-17', 1720460, 1727587, 2812.0, 8, 3110061.0, 5, 1431.0),
            $this->row(2, 22, 'EN_CURSO', '2026-11-20', null, null, null, 0.0, 0, 0.0, 0, 0.0),
            $this->row(3, 23, 'EN_CURSO', '2026-11-21', null, 1000, 1300, 500.0, 2, 900000.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
        );

        self::assertSame(3, $payload['totales']['viajes']);
        self::assertSame(2, $payload['totales']['viajes_con_combustible']);
        self::assertSame(1, $payload['totales']['viajes_sin_combustible']);
        self::assertSame(10, $payload['totales']['cargas']);
        self::assertSame(3312.0, $payload['totales']['litros']);
        self::assertSame(4010061.0, $payload['totales']['importe_ars']);
        self::assertSame(5, $payload['totales']['cargas_sin_precio']);
        self::assertSame(1431.0, $payload['totales']['litros_sin_precio']);

        // El viaje sin combustible dice por qué; el que no tiene precio, cuál carga falta.
        $porNumero = [];
        foreach ($payload['viajes'] as $viaje) {
            $porNumero[$viaje['numero']] = $viaje;
        }

        self::assertSame(
            'Todavía no se registraron cargas de combustible en este viaje.',
            $porNumero[22]['motivo'],
        );
        self::assertStringContainsString('Falta el precio de 5 de las 8 cargas', $porNumero[21]['motivo']);
        self::assertStringContainsString('1.431 L sin valorar', $porNumero[21]['motivo']);
        self::assertNull($porNumero[23]['motivo']);
        self::assertSame(7127, $porNumero[21]['km']);
        self::assertSame(300, $porNumero[23]['km']);
    }

    public function testCalculatesTheConsumptionOfEachTripAndTheAverageOfTheFleet(): void
    {
        $reader = new FuelPanelReaderFake([
            // 2.812 L en 7.127 km.
            $this->row(1, 21, 'FINALIZADO', '2026-10-30', '2026-11-17', 1720460, 1727587, 2812.0, 8, 3110061.0, 5, 1431.0),
            // 500 L en 1.000 km.
            $this->row(2, 22, 'FINALIZADO', '2026-11-01', '2026-11-05', 1000, 2000, 500.0, 2, 900000.0, 0, 0.0),
            // Sin odómetro de regreso: no se puede medir el consumo.
            $this->row(3, 23, 'EN_CURSO', '2026-11-21', null, 1000, null, 500.0, 2, 900000.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
        );

        $porNumero = [];
        foreach ($payload['viajes'] as $viaje) {
            $porNumero[$viaje['numero']] = $viaje;
        }

        self::assertSame(39.5, $porNumero[21]['consumo_l100']);
        self::assertSame(50.0, $porNumero[22]['consumo_l100']);
        self::assertNull($porNumero[23]['consumo_l100']);

        // El promedio de la flota es el de los viajes que se pueden medir, y
        // dice cuántos son: 3.312 L sobre 8.127 km.
        self::assertSame(40.8, $payload['totales']['consumo']['litros_por_100km']);
        self::assertSame(2, $payload['totales']['consumo']['viajes_medidos']);
        self::assertSame(8127, $payload['totales']['consumo']['km']);
    }

    public function testSaysThereIsNoAverageConsumptionWithoutMeasuredTrips(): void
    {
        $reader = new FuelPanelReaderFake([
            $this->row(1, 22, 'EN_CURSO', '2026-11-20', null, null, null, 0.0, 0, 0.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
        );

        self::assertNull($payload['totales']['consumo']['litros_por_100km']);
        self::assertSame(0, $payload['totales']['consumo']['viajes_medidos']);
    }

    public function testListsTheLoadsWaitingForTheirPriceAndWhoCanCompleteThem(): void
    {
        $pendientes = [['id' => 90, 'litros' => 193.0, 'fecha' => '2026-11-13 10:00:00', 'estacion' => 'YPF', 'comprobante' => null]];
        $reader = new FuelPanelReaderFake([
            $this->row(1, 21, 'FINALIZADO', '2026-10-30', '2026-11-17', 1720460, 1727587, 2812.0, 8, 3110061.0, 5, 1431.0, $pendientes),
            $this->row(2, 22, 'EN_CURSO', '2026-11-20', null, null, null, 0.0, 0, 0.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
        );

        $porNumero = [];
        foreach ($payload['viajes'] as $viaje) {
            $porNumero[$viaje['numero']] = $viaje;
        }

        self::assertCount(1, $porNumero[21]['cargas_pendientes']);
        self::assertSame(90, $porNumero[21]['cargas_pendientes'][0]['id']);
        self::assertTrue($porNumero[21]['puede_completar_precio']);
        // Un viaje finalizado no admite cargas nuevas: sólo el precio.
        self::assertFalse($porNumero[21]['puede_cargar']);
        self::assertTrue($porNumero[22]['puede_cargar']);
        self::assertFalse($porNumero[22]['puede_completar_precio']);
    }

    public function testTripsWithoutRealDepartureAreReportedApart(): void
    {
        $reader = new FuelPanelReaderFake([
            $this->row(1, 30, 'EN_CURSO', null, null, null, null, 0.0, 0, 0.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
        );

        self::assertSame([], $payload['viajes']);
        self::assertCount(1, $payload['sin_salida_real']);
        self::assertSame(30, $payload['sin_salida_real'][0]['numero']);
        self::assertSame(
            'El viaje no tiene fecha de salida real cargada.',
            $payload['sin_salida_real'][0]['motivo'],
        );
    }

    public function testTripsOutsideThePeriodAreLeftOut(): void
    {
        $reader = new FuelPanelReaderFake([
            // Terminó antes de la ventana: no se informa.
            $this->row(1, 10, 'FINALIZADO', '2026-09-01', '2026-09-05', 1000, 1100, 100.0, 1, 1000.0, 0, 0.0),
            // Empezó antes y terminó después de la ventana: entra.
            $this->row(2, 11, 'FINALIZADO', '2026-09-01', '2026-11-20', 1000, 1100, 100.0, 1, 1000.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
        );

        $numeros = array_map(static fn (array $viaje): int => $viaje['numero'], $payload['viajes']);
        self::assertSame([11], $numeros);
    }

    public function testThePeriodCanBeChosenWithDates(): void
    {
        $reader = new FuelPanelReaderFake([
            $this->row(1, 11, 'FINALIZADO', '2026-09-01', '2026-09-05', 1000, 1100, 100.0, 1, 1000.0, 0, 0.0),
            $this->row(2, 12, 'FINALIZADO', '2026-10-01', '2026-10-05', 1000, 1100, 100.0, 1, 1000.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
            new FuelPanelFilters('2026-10-01', '2026-10-31'),
        );

        self::assertSame('2026-10-01', $payload['periodo']['desde']);
        self::assertSame('2026-10-31', $payload['periodo']['hasta']);
        self::assertSame([12], array_map(static fn (array $viaje): int => $viaje['numero'], $payload['viajes']));
    }

    public function testRejectsActorWithoutTripPermission(): void
    {
        $this->expectException(DomainException::class);

        (new GetPanelCombustible(new FuelPanelReaderFake([]), new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], [], []),
        );
    }

    public function testRejectsABranchOutsideTheActorScope(): void
    {
        $this->expectException(DomainException::class);

        (new GetPanelCombustible(new FuelPanelReaderFake([]), new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, false, [], ['viajes.ver'], [10]),
            new FuelPanelFilters(null, null, null, 99),
        );
    }

    public function testListsTheTrucksThatCanBeUsedAsFilter(): void
    {
        $reader = new FuelPanelReaderFake([], [['id' => 7, 'label' => 'ONN137 · T07', 'patente' => 'ONN137']]);

        $camiones = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->camiones(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
        );

        self::assertSame([['id' => 7, 'label' => 'ONN137 · T07', 'patente' => 'ONN137']], $camiones);
    }

    /**
     * @param list<array<string,mixed>> $pendientes
     *
     * @return array<string,mixed>
     */
    private function row(
        int $id,
        int $numero,
        string $estado,
        ?string $salida,
        ?string $regreso,
        ?int $kmSalida,
        ?int $kmRegreso,
        float $litros,
        int $cargas,
        float $importe,
        int $cargasSinPrecio,
        float $litrosSinPrecio,
        array $pendientes = [],
    ): array {
        return [
            'id' => $id,
            'numero' => $numero,
            'estado' => $estado,
            'sucursal_id' => 10,
            'sucursal' => 'Posadas',
            'fecha_salida_real' => $salida === null ? null : $salida . ' 08:00:00',
            'fecha_regreso_real' => $regreso === null ? null : $regreso . ' 18:00:00',
            'km_salida' => $kmSalida,
            'km_regreso' => $kmRegreso,
            'patente' => 'ONN137',
            'equipo_id' => 7,
            'litros' => $litros,
            'cargas' => $cargas,
            'importe_ars' => $importe,
            'cargas_sin_precio' => $cargasSinPrecio,
            'litros_sin_precio' => $litrosSinPrecio,
            'cargas_pendientes' => $pendientes,
        ];
    }
}

final class FuelPanelReaderFake implements FuelPanelReader
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array{id:int, label:string, patente:string}> $camiones
     */
    public function __construct(private array $rows, private array $camiones = [])
    {
    }

    public function viajes(int $companyId, ?array $branchIds, FuelPanelFilters $filters): array
    {
        return $this->rows;
    }

    public function camiones(int $companyId): array
    {
        return $this->camiones;
    }
}

final class FuelPanelClockFake implements TripClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-24 15:00:00');
    }
}
