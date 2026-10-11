<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic;

use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MedidaAdicional;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InstantaneaEquipoTest extends TestCase
{
    private const AHORA = '2026-10-09 12:00:00';

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AHORA);
    }

    private function posicion(string $observada = '2026-10-09 11:30:00'): Posicion
    {
        return new Posicion(-33.0944, -68.8800, 29.0, 216, 955.4, 25, new DateTimeImmutable($observada));
    }

    public function testLaPosicionExponeTodosSusDatos(): void
    {
        $posicion = $this->posicion();

        self::assertSame(-33.0944, $posicion->latitude());
        self::assertSame(-68.88, $posicion->longitude());
        self::assertSame(29.0, $posicion->speedKmh());
        self::assertSame(216, $posicion->course());
        self::assertSame(955.4, $posicion->altitudeMeters());
        self::assertSame(25, $posicion->satellites());
        self::assertTrue($posicion->estaEnMovimiento());
        self::assertSame(30, $posicion->antiguedadMinutos($this->ahora()));
    }

    public function testRechazaCoordenadasFueraDeRango(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Posicion(120.0, -68.88, null, null, null, null, $this->ahora());
    }

    public function testLaInstantaneaGuardaLaAntiguedadDesdeLaObservacion(): void
    {
        $instantanea = new InstantaneaEquipo(
            new DateTimeImmutable('2026-10-07 12:00:00'),
            null,
            1_865_007,
            43_559,
            false,
            null,
            24.51,
            660.48,
        );

        self::assertSame(2880, $instantanea->antiguedadMinutos($this->ahora()));
        self::assertSame(1_865_007, $instantanea->kilometraje());
        self::assertSame(43_559, $instantanea->horasDecimales());
        self::assertFalse($instantanea->motorEncendido());
        self::assertSame(24.51, $instantanea->voltaje());
        self::assertSame(660.48, $instantanea->combustibleLitros());
    }

    public function testLosSensoresNoInterpretadosConservanSuEtiquetaOriginal(): void
    {
        $medida = new MedidaAdicional('COMBUSTIBLE T1', 494.03, 'l', 'custom');

        self::assertSame('COMBUSTIBLE T1', $medida->etiqueta());
        self::assertSame(494.03, $medida->valor());
        self::assertSame('l', $medida->unidad());
        self::assertSame('custom', $medida->tipoProveedor());
        self::assertSame('COMBUSTIBLE T1: 494,03 l', $medida->texto());
    }

    public function testElEstadoDerivaLaFechaDeLaInstantaneaYNoDeUnCampoSuelto(): void
    {
        $estado = new EstadoSenal('28396292', new InstantaneaEquipo(
            new DateTimeImmutable('2026-10-08 06:00:00'),
            $this->posicion('2026-10-08 06:00:00'),
            null,
            null,
            null,
            null,
            null,
            null,
        ));

        self::assertSame('2026-10-08 06:00:00', $estado->ultimaSenalEn()?->format('Y-m-d H:i:s'));
        self::assertSame('20261008060000', $estado->ciclo());
        self::assertTrue($estado->estaEstancada($this->ahora(), 24));
    }

    public function testUnEstadoSinInstantaneaNoEstaEstancadoNiTieneCicloDeFecha(): void
    {
        $estado = new EstadoSenal('28396292', null);

        self::assertFalse($estado->estaEstancada($this->ahora(), 1));
        self::assertNull($estado->ultimaSenalEn());
        self::assertSame('sin_senal', $estado->ciclo());
    }
}