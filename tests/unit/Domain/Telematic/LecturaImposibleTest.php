<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\LecturaImposible;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Un valor imposible no es "no hay dato": es un síntoma que alguien tiene que
 * ir a mirar. Estos tests fijan que el valor se aparta Y que la información no
 * se pierde.
 */
final class LecturaImposibleTest extends TestCase
{
    private function instantanea(
        ?float $voltaje,
        ?float $combustible,
        ?int $kilometraje = 1866615,
        ?int $horas = 49462,
    ): InstantaneaEquipo {
        $momento = new DateTimeImmutable('2026-10-09 12:00:00');

        return new InstantaneaEquipo(
            $momento,
            new Posicion(-33.09, -68.88, 0.0, null, null, null, $momento),
            $kilometraje,
            $horas,
            false,
            false,
            $voltaje,
            $combustible,
        );
    }

    public function testUnCombustibleNegativoSeApartaYQuedaRegistrado(): void
    {
        $instantanea = $this->instantanea(25.0, -348201.39);

        self::assertNull($instantanea->combustibleLitros(), 'El valor imposible no llega a la base.');
        self::assertTrue($instantanea->tieneAnomalias());

        $anomalias = $instantanea->anomalias();
        self::assertCount(1, $anomalias);
        self::assertSame('COMBUSTIBLE', $anomalias[0]->concepto());
        self::assertSame(-348201.39, $anomalias[0]->valorLeido());
        self::assertStringContainsString('348.201,39', $anomalias[0]->resumen());
        self::assertStringContainsString('entrada analítica', $anomalias[0]->motivo());
    }

    public function testUnaBateriaEnCeroSeApartaYQuedaRegistrada(): void
    {
        $instantanea = $this->instantanea(0.0, 500.0);

        self::assertNull($instantanea->voltaje());
        self::assertSame('VOLTAJE', $instantanea->anomalias()[0]->concepto());
        self::assertStringContainsString('sensor apagado', $instantanea->anomalias()[0]->motivo());
    }

    public function testVariasAnomaliasEnLaMismaInstantanea(): void
    {
        $instantanea = $this->instantanea(0.0, -348201.39, -5, -100);

        $conceptos = array_map(static fn (LecturaImposible $a): string => $a->concepto(), $instantanea->anomalias());
        sort($conceptos);

        self::assertSame(['COMBUSTIBLE', 'HORAS', 'KILOMETRAJE', 'VOLTAJE'], $conceptos);
    }

    public function testUnaLecturaPlausibleNoGeneraAnomalia(): void
    {
        $instantanea = $this->instantanea(25.39, 652.46);

        self::assertFalse($instantanea->tieneAnomalias());
        self::assertSame([], $instantanea->anomalias());
        self::assertSame(652.46, $instantanea->combustibleLitros());
    }

    public function testElRestoDeLaInstantaneaSigueSiendoValido(): void
    {
        $instantanea = $this->instantanea(0.0, -348201.39);

        self::assertNotNull($instantanea->posicion(), 'Un sensor colgado no invalida la posición.');
        self::assertSame(1866615, $instantanea->kilometraje());
        self::assertSame(49462, $instantanea->horasDecimales());
    }

    public function testLaFirmaDistingueLaFallaYSuValor(): void
    {
        $negativo = new LecturaImposible('COMBUSTIBLE', -348201.39, 'motivo');
        $otroValor = new LecturaImposible('COMBUSTIBLE', -1000.0, 'motivo');
        $otroConcepto = new LecturaImposible('VOLTAJE', -348201.39, 'motivo');

        self::assertNotSame($negativo->firma(), $otroValor->firma(), 'Un valor distinto es otra falla.');
        self::assertNotSame($negativo->firma(), $otroConcepto->firma(), 'Otro sensor es otra falla.');
        self::assertSame($negativo->firma(), $negativo->firma(), 'La misma falla no puede duplicar la alerta.');
    }

    public function testElConceptoEsNuestroYNoDelProveedor(): void
    {
        $anomalia = new LecturaImposible('COMBUSTIBLE', -348201.39, 'motivo');

        self::assertStringStartsWith('COMBUSTIBLE:', $anomalia->resumen());
        self::assertStringNotContainsStringIgnoringCase('COMBUSTIBLE T1', $anomalia->resumen());
    }
}