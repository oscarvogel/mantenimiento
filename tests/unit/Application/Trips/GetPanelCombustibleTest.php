<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
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
            30,
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

    public function testTripsWithoutRealDepartureAreReportedApart(): void
    {
        $reader = new FuelPanelReaderFake([
            $this->row(1, 30, 'EN_CURSO', null, null, null, null, 0.0, 0, 0.0, 0, 0.0),
        ]);

        $payload = (new GetPanelCombustible($reader, new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
            30,
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
            30,
        );

        $numeros = array_map(static fn (array $viaje): int => $viaje['numero'], $payload['viajes']);
        self::assertSame([11], $numeros);
    }

    public function testRejectsActorWithoutTripPermission(): void
    {
        $this->expectException(DomainException::class);

        (new GetPanelCombustible(new FuelPanelReaderFake([]), new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], [], []),
            30,
        );
    }

    public function testRejectsPeriodOutOfRange(): void
    {
        $this->expectException(DomainException::class);

        (new GetPanelCombustible(new FuelPanelReaderFake([]), new FuelPanelClockFake()))->execute(
            new ActorContext(7, 4, false, true, [], ['viajes.ver'], []),
            0,
        );
    }

    /**
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
            'litros' => $litros,
            'cargas' => $cargas,
            'importe_ars' => $importe,
            'cargas_sin_precio' => $cargasSinPrecio,
            'litros_sin_precio' => $litrosSinPrecio,
        ];
    }
}

final class FuelPanelReaderFake implements FuelPanelReader
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function viajes(int $companyId, ?array $branchIds): array
    {
        return $this->rows;
    }
}

final class FuelPanelClockFake implements TripClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-24 15:00:00');
    }
}
