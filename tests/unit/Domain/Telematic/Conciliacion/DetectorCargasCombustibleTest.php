<?php

declare(strict_types=1);

namespace Tests\unit\Domain\Telematic\Conciliacion;

use App\Domain\Telematic\Conciliacion\DetectorCargasCombustible;
use PHPUnit\Framework\TestCase;
use Tests\Support\Telematic\SerieTanque;

final class DetectorCargasCombustibleTest extends TestCase
{
    public function testUnaCargaEnUnSoloEscalonSeDetectaConSusLitros(): void
    {
        $serie = SerieTanque::cada(15, '2026-10-26 08:00', [300, 300, 299, 680, 681, 680, 680, 679]);

        $cargas = (new DetectorCargasCombustible())->detectar($serie);

        self::assertCount(1, $cargas);
        self::assertEqualsWithDelta(380.0, $cargas[0]->litros(), 1.0);
    }

    public function testElInicioEsLaUltimaLecturaAntesDeLaSubidaYNoElBordeDeLaVentana(): void
    {
        // Seis horas estable antes de la carga: la ventana la alcanza desde temprano.
        $serie = SerieTanque::cada(30, '2026-10-26 04:00', array_merge(array_fill(0, 12, 300), array_fill(0, 6, 700)));

        $cargas = (new DetectorCargasCombustible())->detectar($serie);

        self::assertCount(1, $cargas);
        self::assertSame('09:30', $cargas[0]->inicio()->format('H:i'));
    }

    public function testUnaCargaEnVariosEscalonesSeJuntaEnUnaSola(): void
    {
        // Dos surtidores seguidos: ningún escalón llega solo a 60 L... salvo juntos.
        $serie = SerieTanque::cada(15, '2026-10-26 08:00', [300, 300, 300, 340, 385, 430, 430, 430, 429]);

        $cargas = (new DetectorCargasCombustible())->detectar($serie);

        self::assertCount(1, $cargas);
        self::assertEqualsWithDelta(130.0, $cargas[0]->litros(), 1.0);
    }

    public function testUnaSubidaDelSensorQueVuelveConElCamionParadoNoEsCarga(): void
    {
        // Caso real de HWW968 el 25/10: parado, motor apagado, el nivel sube al
        // mediodía y vuelve a la noche. Una muestra por hora.
        $serie = SerieTanque::cada(60, '2026-10-25 08:00', [
            276, 276, 279, 281, 290, 340, 345, 342, 335, 320, 300, 285, 280, 278, 277, 277, 277,
        ]);

        self::assertSame([], (new DetectorCargasCombustible())->detectar($serie));
    }

    public function testSiElCamionSalioDespuesDeCargarNoSeExigeQueElNivelSigaArriba(): void
    {
        // Carga y salida a la ruta: el consumo baja el nivel en las horas siguientes.
        $litros = [200, 200, 200, 700, 700, 700];
        $km = [1000, 1000, 1000, 1000, 1000, 1000];
        for ($h = 1; $h <= 32; $h++) {
            $litros[] = 700 - $h * 12;
            $km[] = 1000 + $h * 20;
        }

        $cargas = (new DetectorCargasCombustible())->detectar(SerieTanque::cada(15, '2026-10-26 08:00', $litros, $km));

        self::assertCount(1, $cargas);
        self::assertEqualsWithDelta(500.0, $cargas[0]->litros(), 1.0);
    }

    public function testUnaLecturaSueltaMalaNoSeCuentaComoCarga(): void
    {
        $serie = SerieTanque::cada(15, '2026-10-26 08:00', [300, 300, 300, 520, 299, 298, 298, 297]);

        self::assertSame([], (new DetectorCargasCombustible())->detectar($serie));
    }

    public function testLasLecturasSinSensorSeIgnoranYNoInventanCargas(): void
    {
        $serie = SerieTanque::cada(15, '2026-10-26 08:00', [null, null, 300, null, 300, 301, null, 300]);

        self::assertSame([], (new DetectorCargasCombustible())->detectar($serie));
    }

    public function testDosCargasSeparadasSeCuentanPorSeparado(): void
    {
        $litros = array_merge(
            [200, 200, 200, 600, 600, 600],
            array_fill(0, 40, 600),
            [600, 600, 900, 900, 900],
        );

        $cargas = (new DetectorCargasCombustible())->detectar(SerieTanque::cada(15, '2026-10-26 08:00', $litros));

        self::assertCount(2, $cargas);
        self::assertEqualsWithDelta(400.0, $cargas[0]->litros(), 1.0);
        self::assertEqualsWithDelta(300.0, $cargas[1]->litros(), 1.0);
    }
}
