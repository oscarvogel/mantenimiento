<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Valores imposibles que devolvió la flota real y que el dominio debe
 * normalizar a ausencia de dato en vez de propagar.
 */
final class InstantaneaSanidadTest extends TestCase
{
    private function instantanea(
        ?float $voltaje,
        ?float $combustible,
        ?int $kilometraje = 1000,
        ?int $horas = 100,
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

    public function testUnCombustibleNegativoNoEsUnTanqueVacio(): void
    {
        // Valor real devuelto por tres unidades de la flota: la entrada
        // analogica esta desconectada, no el tanque en negativo.
        $instantanea = $this->instantanea(25.0, -348201.39);

        self::assertNull($instantanea->combustibleLitros());
    }

    public function testUnCombustibleCeroSiEsUnValorValido(): void
    {
        self::assertSame(0.0, $this->instantanea(25.0, 0.0)->combustibleLitros());
    }

    public function testUnaBateriaEnCeroNoEsUnaMedida(): void
    {
        // Valor real devuelto por una unidad: 0,00 V significa sensor apagado.
        self::assertNull($this->instantanea(0.0, 500.0)->voltaje());
    }

    public function testUnaBateriaNegativaTampocoEsUnaMedida(): void
    {
        self::assertNull($this->instantanea(-1.5, 500.0)->voltaje());
    }

    public function testLosValoresPlausiblesSeConservan(): void
    {
        $instantanea = $this->instantanea(25.39, 652.46);

        self::assertSame(25.39, $instantanea->voltaje());
        self::assertSame(652.46, $instantanea->combustibleLitros());
    }

    public function testUnKilometrajeNegativoSeNormaliza(): void
    {
        self::assertNull($this->instantanea(25.0, 500.0, -1, 100)->kilometraje());
        self::assertNull($this->instantanea(25.0, 500.0, 1000, -1)->horasDecimales());
    }

    public function testNormalizarUnValorNoInvalidaElResto(): void
    {
        $instantanea = $this->instantanea(0.0, -1.0, 1866615, 49462);

        self::assertNotNull($instantanea->posicion(), 'La posicion sigue siendo valida aunque el combustible no lo sea.');
        self::assertSame(1866615, $instantanea->kilometraje());
        self::assertSame(49462, $instantanea->horasDecimales());
        self::assertNull($instantanea->voltaje());
        self::assertNull($instantanea->combustibleLitros());
    }
}