<?php

declare(strict_types=1);

namespace Tests\unit\Domain\PreventiveMaintenance\Gps;

use App\Domain\PreventiveMaintenance\Gps\EstimacionKmGps;
use App\Domain\PreventiveMaintenance\Gps\LecturaKmVigente;
use App\Domain\PreventiveMaintenance\Gps\OdometroGps;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EstimacionKmGpsTest extends TestCase
{
    private static function en(string $fecha): DateTimeImmutable
    {
        return new DateTimeImmutable($fecha, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    private static function gps(string $fecha, int $km): OdometroGps
    {
        return new OdometroGps(self::en($fecha), $km);
    }

    private static function lectura(string $fecha = '2026-11-16 12:48', int $km = 1013505): LecturaKmVigente
    {
        return new LecturaKmVigente(42, $km, self::en($fecha));
    }

    public function testSumaALaLecturaSoloLaDiferenciaDelGpsDesdeEseMomento(): void
    {
        // El GPS tiene otra base que el tablero (3.696 km menos acá): solo cuenta la diferencia.
        $estimacion = EstimacionKmGps::estimar(
            self::lectura(),
            [self::gps('2026-11-16 12:30', 1009809), self::gps('2026-11-20 20:00', 1014197)],
            self::en('2026-11-20 20:05'),
        );

        self::assertTrue($estimacion->esEstimable());
        self::assertSame(4388, $estimacion->kmGpsDesdeLectura());
        self::assertSame(1017893, $estimacion->kmEstimado());
        self::assertSame('20:00', $estimacion->ultimo()?->en->format('H:i'));
    }

    public function testElAnclaEsElValorMasCercanoALaLecturaYAIgualDistanciaElAnterior(): void
    {
        $estimacion = EstimacionKmGps::estimar(
            self::lectura('2026-11-16 12:00'),
            [
                self::gps('2026-11-20 20:00', 5000),
                self::gps('2026-11-16 13:00', 1100),
                self::gps('2026-11-16 11:00', 1000),
                self::gps('2026-11-16 08:00', 900),
            ],
            self::en('2026-11-20 20:05'),
        );

        self::assertSame(1000, $estimacion->ancla()?->km);
        self::assertSame(4000, $estimacion->kmGpsDesdeLectura());
    }

    public function testSinGpsCercaDeLaLecturaNoSeEstima(): void
    {
        // El corte de Wialon: el último dato del GPS antes de la lectura es de 10 h antes.
        $estimacion = EstimacionKmGps::estimar(
            self::lectura('2026-11-16 12:00'),
            [self::gps('2026-11-16 02:00', 1000), self::gps('2026-11-20 20:00', 5000)],
            self::en('2026-11-20 20:05'),
        );

        self::assertFalse($estimacion->esEstimable());
        self::assertNull($estimacion->kmEstimado());
        self::assertSame('El GPS no tiene datos dentro de las 6 h de la última lectura.', $estimacion->motivo());
    }

    public function testSinLecturaVigenteNoSeEstima(): void
    {
        $estimacion = EstimacionKmGps::estimar(null, [self::gps('2026-11-20 20:00', 5000)], self::en('2026-11-20 20:05'));

        self::assertSame('No hay una lectura vigente del odómetro.', $estimacion->motivo());
        self::assertNull($estimacion->kmGpsDesdeLectura());
    }

    public function testSinOdometroDeGpsNoSeEstima(): void
    {
        $estimacion = EstimacionKmGps::estimar(self::lectura(), [], self::en('2026-11-20 20:05'));

        self::assertSame('El equipo no tiene odómetro de GPS.', $estimacion->motivo());
    }

    public function testUnaLecturaDeMasDeSesentaDiasNoSeEstima(): void
    {
        $estimacion = EstimacionKmGps::estimar(
            self::lectura('2026-09-01 10:00'),
            [self::gps('2026-09-01 10:00', 1000), self::gps('2026-11-20 20:00', 9000)],
            self::en('2026-11-20 20:05'),
        );

        self::assertSame('La última lectura tiene 80 días: falta cargar el odómetro.', $estimacion->motivo());
    }

    public function testUnOdometroDeGpsQueRetrocedeNoSeEstima(): void
    {
        $estimacion = EstimacionKmGps::estimar(
            self::lectura(),
            [self::gps('2026-11-16 12:30', 1009809), self::gps('2026-11-20 20:00', 1009000)],
            self::en('2026-11-20 20:05'),
        );

        self::assertSame('El odómetro del GPS retrocedió desde la última lectura.', $estimacion->motivo());
    }

    public function testUnSaltoDelOdometroDelGpsNoSeEstima(): void
    {
        // 2.000 km en 4 horas son 500 km/h de media.
        $estimacion = EstimacionKmGps::estimar(
            self::lectura('2026-11-20 10:00'),
            [self::gps('2026-11-20 10:00', 1000), self::gps('2026-11-20 14:00', 3000)],
            self::en('2026-11-20 14:05'),
        );

        self::assertSame('El GPS sumó 2000 km en 4,0 h: el odómetro del GPS saltó.', $estimacion->motivo());
    }

    public function testSinKmNuevosDelGpsLaEstimacionEsLaLectura(): void
    {
        // El camión no se movió desde la lectura: cero km es un dato, no un faltante.
        $estimacion = EstimacionKmGps::estimar(
            self::lectura('2026-11-20 09:57', 1043494),
            [self::gps('2026-11-20 09:45', 1039537), self::gps('2026-11-20 09:50', 1039537)],
            self::en('2026-11-20 20:05'),
        );

        self::assertSame(0, $estimacion->kmGpsDesdeLectura());
        self::assertSame(1043494, $estimacion->kmEstimado());
    }
}
