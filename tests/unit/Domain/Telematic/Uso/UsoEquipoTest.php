<?php

declare(strict_types=1);

namespace Tests\unit\Domain\Telematic\Uso;

use App\Domain\Telematic\Uso\UsoEquipo;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Telematic\SerieUso;

final class UsoEquipoTest extends TestCase
{
    private function fecha(string $valor): DateTimeImmutable
    {
        return new DateTimeImmutable($valor, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    private function medir(array $muestras, string $desde = '2026-10-26 00:00', string $hasta = '2026-10-28 00:00'): UsoEquipo
    {
        return UsoEquipo::medir($muestras, $this->fecha($desde), $this->fecha($hasta));
    }

    public function testKmYHorasSalenDeOdometroYHorometroAunqueHayaHuecos(): void
    {
        $uso = $this->medir(SerieUso::pasos('2026-10-26 08:00', [
            [0, 0.0, true, false, 1000, 40000],
            [600, 80.0, true, false, 1500, 40080], // 10 h después: hueco.
            [1200, 0.0, false, false, 1900, 40150],
        ]));

        self::assertSame(900, $uso->km());
        self::assertEqualsWithDelta(450.0, $uso->kmPorDia(), 0.1);
        self::assertEqualsWithDelta(15.0, $uso->horasMotor(), 0.01);
    }

    public function testElTiempoDetenidoConMotorSeMideSoloEnTramosContinuos(): void
    {
        // 60 min en ruta y 60 min detenido con motor, en tramos de 15 min.
        $pasos = [];
        for ($i = 0; $i < 4; $i++) {
            $pasos[] = [$i * 15, 80.0, true, false, 1000 + $i * 20, null];
        }
        for ($i = 4; $i < 8; $i++) {
            $pasos[] = [$i * 15, 0.0, true, true, 1080, null];
        }
        $pasos[] = [120, 0.0, false, false, 1080, null];
        // Y un tramo de 3 h que no se reparte.
        $pasos[] = [300, 0.0, true, true, 1080, null];

        $uso = $this->medir(SerieUso::pasos('2026-10-26 08:00', $pasos));

        self::assertSame(120, $uso->minutosMotorMedidos());
        self::assertEqualsWithDelta(50.0, $uso->porcentajeMotorDetenido(), 0.1);
        self::assertEqualsWithDelta(50.0, $uso->porcentajeRalenti(), 0.1);
    }

    public function testSinSensorDeRalentiNoHayPorcentajeDeRalenti(): void
    {
        $pasos = [];
        for ($i = 0; $i <= 8; $i++) {
            $pasos[] = [$i * 15, 0.0, true, null, 1000, null];
        }

        $uso = $this->medir(SerieUso::pasos('2026-10-26 08:00', $pasos));

        self::assertNotNull($uso->porcentajeMotorDetenido());
        self::assertNull($uso->porcentajeRalenti(), 'Sin sensor no es 0% de ralentí: es que no se sabe.');
    }

    public function testConPocoMotorMedidoNoSeInformanPorcentajes(): void
    {
        $uso = $this->medir(SerieUso::pasos('2026-10-26 08:00', [
            [0, 0.0, true, true, 1000, null],
            [15, 0.0, true, true, 1000, null],
        ]));

        self::assertNull($uso->porcentajeMotorDetenido());
        self::assertNull($uso->porcentajeRalenti());
    }

    public function testUnExcesoSonDosMuestrasSeguidasPorEncimaDelUmbral(): void
    {
        $uso = $this->medir(SerieUso::pasos('2026-10-26 08:00', [
            [0, 85.0, true, false, 1000, null],
            [5, 95.0, true, false, 1008, null],
            [10, 112.0, true, false, 1017, null],
            [15, 86.0, true, false, 1025, null],
            // Una sola muestra alta: puede ser un salto del GPS.
            [20, 130.0, true, false, 1033, null],
            [25, 86.0, true, false, 1040, null],
            // Dos altas separadas por un hueco: no son el mismo tramo.
            [30, 95.0, true, false, 1048, null],
            [120, 96.0, true, false, 1150, null],
        ]));

        self::assertCount(1, $uso->excesos());
        self::assertSame(112.0, $uso->excesos()[0]->velocidadMaximaKmh);
        self::assertSame(2, $uso->excesos()[0]->muestras);
        self::assertSame(130.0, $uso->velocidadMaximaKmh());
    }

    public function testSinMuestrasEnElPeriodoNoHayIndicadores(): void
    {
        $uso = $this->medir(SerieUso::pasos('2026-10-20 08:00', [[0, 80.0, true, false, 1000, 1]]));

        self::assertTrue($uso->sinDatos());
        self::assertNull($uso->km());
        self::assertNull($uso->ultimoDato());
        self::assertNull($uso->minutosSinReportar($this->fecha('2026-10-28 00:00')));
    }

    public function testMinutosSinReportarDesdeElUltimoDato(): void
    {
        $uso = $this->medir(SerieUso::pasos('2026-10-26 08:00', [[0, 0.0, false, false, 1000, null]]));

        self::assertSame(1440, $uso->minutosSinReportar($this->fecha('2026-10-27 08:00')));
    }
}
