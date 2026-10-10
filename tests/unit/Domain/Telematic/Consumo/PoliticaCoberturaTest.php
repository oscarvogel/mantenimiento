<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic\Consumo;

use App\Domain\Telematic\Consumo\CoberturaPeriodo;
use App\Domain\Telematic\Consumo\IntervaloConsumo;
use App\Domain\Telematic\Consumo\PoliticaAtribucion;
use App\Domain\Telematic\Consumo\PoliticaCobertura;
use App\Domain\Telematic\Consumo\ReglaSegmentacion;
use App\Domain\Telematic\Consumo\SerieConsumo;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Si el período evaluado tiene suficientes datos para decir algo.
 *
 * La cobertura responde a una pregunta distinta de la del intervalo. El
 * intervalo pregunta "el hueco lo vuelve inservible"; el período pregunta
 * "de todo el rango que el operador pidió mirar, ¿cuánto sabemos realmente?".
 *
 * El caso que más importa y que no aparece en los tests habituales: pedir
 * las últimas ocho horas de un equipo que lleva tres horas sin reportar. Las
 * cinco horas que sí hay son datos buenos, pero la cola sin cubrir es una
 * ausencia, y el operador tiene que verla.
 *
 * @internal
 */
final class PoliticaCoberturaTest extends TestCase
{
    public function testUnaSerieCompletaCubreTodoElPeriodo(): void
    {
        $cobertura = $this->evaluar(
            $this->serieCada15Minutos('2026-10-10 06:00:00', 17),
            '2026-10-10 06:00:00',
            '2026-10-10 10:00:00',
        );

        self::assertSame(240, $cobertura->minutosEsperados());
        self::assertSame(240, $cobertura->minutosCubiertos());
        self::assertEqualsWithDelta(1.0, $cobertura->cobertura(), 0.0005);
        self::assertSame([], $cobertura->huecosMinutos());
        self::assertTrue($cobertura->suficiente());
        self::assertNull($cobertura->motivoInsuficiente());
    }

    public function testUnaColaSinCubrirBajaLaProporcion(): void
    {
        // El operador pide ocho horas, pero el equipo lleva tres sin reportar.
        // Las cinco que hay son buenas; la cola es una ausencia.
        $cobertura = $this->evaluar(
            $this->serieCada15Minutos('2026-10-10 06:00:00', 21),
            '2026-10-10 06:00:00',
            '2026-10-10 14:00:00',
        );

        self::assertSame(480, $cobertura->minutosEsperados());
        self::assertSame(300, $cobertura->minutosCubiertos());
        self::assertEqualsWithDelta(0.625, $cobertura->cobertura(), 0.0005);
        self::assertFalse($cobertura->suficiente());
        self::assertStringContainsString('62', (string) $cobertura->motivoInsuficiente());
    }

    public function testUnHuecoInternoCuentaComoNoCubierto(): void
    {
        // 06:00 a 10:00 son 240 minutos. Hay un hueco de dos horas en el medio:
        // cubren 120 de los 240, así que el período es la mitad.
        $muestras = $this->serieCada15Minutos('2026-10-10 06:00:00', 5);
        $muestras = array_merge($muestras, $this->serieCada15Minutos('2026-10-10 08:00:00', 9));

        $cobertura = $this->evaluar($muestras, '2026-10-10 06:00:00', '2026-10-10 10:00:00');

        self::assertSame(240, $cobertura->minutosEsperados());
        self::assertSame(180, $cobertura->minutosCubiertos());
        self::assertEqualsWithDelta(0.75, $cobertura->cobertura(), 0.0005);
        self::assertSame([60], $cobertura->huecosMinutos());
        self::assertFalse($cobertura->suficiente(), 'Un hueco de una hora supera el máximo aunque la proporción alcance.');
    }

    public function testUnHuecoGrandeInvalidaAunqueLaProporcionAlcanze(): void
    {
        // 480 minutos, 420 cubiertos: 87,5 % de cobertura, por encima del
        // umbral. Pero hay un hueco de dos horas en el medio, y afuera de
        // ese tramo el consumo no se puede atribuir.
        $muestras = $this->serieCada15Minutos('2026-10-10 06:00:00', 5);
        $muestras = array_merge($muestras, $this->serieCada15Minutos('2026-10-10 08:15:00', 22));

        $cobertura = $this->evaluar($muestras, '2026-10-10 06:00:00', '2026-10-10 14:00:00');

        self::assertGreaterThan(0.70, $cobertura->cobertura());
        self::assertFalse($cobertura->suficiente(), 'La cobertura alta no tapa un hueco grande.');
        self::assertStringContainsString('hueco', (string) $cobertura->motivoInsuficiente());
    }

    public function testUnaSerieVaciaNoEsUnPeriodoSuficiente(): void
    {
        $cobertura = $this->evaluar([], '2026-10-10 06:00:00', '2026-10-10 14:00:00');

        self::assertSame(0.0, $cobertura->cobertura());
        self::assertFalse($cobertura->suficiente());
        self::assertStringContainsString('Sin datos', (string) $cobertura->motivoInsuficiente());
    }

    public function testUnaCapturaSolaNoAlcanzaParaUnPeriodoDeVariasHoras(): void
    {
        $cobertura = $this->evaluar(
            $this->serieCada15Minutos('2026-10-10 06:00:00', 2),
            '2026-10-10 06:00:00',
            '2026-10-10 14:00:00',
        );

        self::assertSame(15, $cobertura->minutosCubiertos());
        self::assertFalse($cobertura->suficiente());
    }

    public function testElUmbralEsConfigurable(): void
    {
        $muestras = $this->serieCada15Minutos('2026-10-10 06:00:00', 5);
        $muestras = array_merge($muestras, $this->serieCada15Minutos('2026-10-10 08:00:00', 9));

        $desde = new DateTimeImmutable('2026-10-10 06:00:00');
        $hasta = new DateTimeImmutable('2026-10-10 10:00:00');

        // Con el umbral por defecto, 75 % de cobertura no alcanza porque el
        // hueco de una hora supera el máximo.
        self::assertFalse(
            (new PoliticaCobertura())->evaluar($this->intervalos($muestras), $desde, $hasta)->suficiente(),
        );

        // Bajando el umbral, el mismo período pasa. Por eso son valores y no
        // constantes.
        self::assertTrue(
            (new PoliticaCobertura(coberturaMinima: 0.40, huecoMaximoMinutos: 90))
                ->evaluar($this->intervalos($muestras), $desde, $hasta)
                ->suficiente(),
        );
    }

    /** @param list<MuestraEquipo> $muestras */
    private function evaluar(array $muestras, string $desde, string $hasta): CoberturaPeriodo
    {
        return (new PoliticaCobertura())->evaluar(
            $this->intervalos($muestras),
            new DateTimeImmutable($desde),
            new DateTimeImmutable($hasta),
        );
    }

    /**
     * @param  list<MuestraEquipo> $muestras
     * @return list<IntervaloConsumo>
     */
    private function intervalos(array $muestras): array
    {
        return (new SerieConsumo(
            $muestras,
            new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 20.0, huecoMaximoMinutos: 30),
        ))->intervalos();
    }

    /**
     * Serie regular de $cantidad capturas cada 15 minutos.
     *
     * @return list<MuestraEquipo>
     */
    private function serieCada15Minutos(string $inicio, int $cantidad): array
    {
        $muestras = [];
        $instante = new DateTimeImmutable($inicio);

        for ($i = 0; $i < $cantidad; $i++) {
            $muestras[] = new MuestraEquipo(
                7,
                1,
                42,
                'WIALON-42',
                'wialon',
                new InstantaneaEquipo(
                    $instante,
                    new Posicion(-32.8051950, -70.0725300, 0.0, 320, 3217.30, 18, $instante),
                    487000 + ($i * 100),
                    40625 + $i,
                    false,
                    false,
                    28.52,
                    500.0,
                ),
                $instante,
            );

            $instante = $instante->modify('+15 minutes');
        }

        return $muestras;
    }

    public function testLaSerieEsElUnicoQueOrdenaLosIntervalos(): void
    {
        // Las capturas llegan en el orden que las devuelva el proveedor. Si la
        // serie no las ordenara, un intervalo negativo cruzaría el cálculo y
        // el consumo del período sería inventado.
        $repartidas = array_reverse($this->serieCada15Minutos('2026-10-10 06:00:00', 5));
        $serie = new SerieConsumo(
            $repartidas,
            new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 20.0),
        );

        $intervalos = $serie->intervalos();

        self::assertCount(4, $intervalos);

        foreach ($intervalos as $intervalo) {
            self::assertTrue($intervalo->desde() < $intervalo->hasta());
        }
    }

    public function testLaSegmentacionUsaLaSerieOrdenada(): void
    {
        $repartidas = array_reverse($this->serieCada15Minutos('2026-10-10 06:00:00', 6));

        $viajes = (new ReglaSegmentacion(new \App\Domain\Telematic\Consumo\PoliticaSegmentacion()))
            ->segmentar(
                $repartidas,
                new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 20.0),
            );

        self::assertNotSame([], $viajes, 'Con las capturas desordenadas el odómetro retrocede y no habría viajes.');
    }
}