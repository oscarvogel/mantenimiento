<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Assets;

use App\Domain\Assets\SaltoKilometrico;
use PHPUnit\Framework\TestCase;

/**
 * El caso real: ITV9J84 pasó de 1.147.308 km a 11.515.914 km en un cierre
 * de orden de trabajo. No es un retroceso, así que la guarda existente lo
 * dejó pasar y el odómetro quedó contaminado.
 */
final class SaltoKilometricoTest extends TestCase
{
    public function testLaErrataDeTipeoSeMarcaComoImplausible(): void
    {
        $salto = new SaltoKilometrico(1147308, 11515914);

        self::assertTrue($salto->esImplausible());
        self::assertFalse($salto->esRetroceso());
        self::assertSame(10368606, $salto->diferencia());
        self::assertSame(10.0, $salto->multiplica());
        self::assertStringContainsString('10 veces', $salto->descripcion());
    }

    public function testUnRecorridoNormalNoSeMarca(): void
    {
        // 630 km en un día: absolutamente normal para un camión.
        self::assertFalse((new SaltoKilometrico(1719616, 1720246))->esImplausible());
    }

    public function testUnAumentoGrandeSobreUnEquipoNuevoNoSeMarca(): void
    {
        // Sin historial comparable, la regla no debe Inventar una falta.
        self::assertFalse((new SaltoKilometrico(null, 5000))->esImplausible());
        self::assertFalse((new SaltoKilometrico(10, 5000))->esImplausible());
    }

    public function testLaPrimeraLecturaNuncaEsImplausible(): void
    {
        $salto = new SaltoKilometrico(null, 11515914);

        self::assertFalse($salto->esImplausible());
        self::assertNull($salto->diferencia());
        self::assertStringContainsString('primera lectura', $salto->descripcion());
    }

    public function testUnRetrocesoNoEsSaltoHaciaAdelante(): void
    {
        $salto = new SaltoKilometrico(11515914, 1147308);

        self::assertTrue($salto->esRetroceso());
        self::assertFalse($salto->esImplausible());
    }

    public function testUnaLecturaSinKilometrajeNoSeEvalua(): void
    {
        $salto = new SaltoKilometrico(1000, null);

        self::assertFalse($salto->esImplausible());
        self::assertNull($salto->diferencia());
    }

    public function testLaDescripcionSirveParaExplicarleAlOperador(): void
    {
        $descripcion = (new SaltoKilometrico(1147308, 11515914))->descripcion();

        self::assertStringContainsString('1.147.308', $descripcion);
        self::assertStringContainsString('11.515.914', $descripcion);
    }
}