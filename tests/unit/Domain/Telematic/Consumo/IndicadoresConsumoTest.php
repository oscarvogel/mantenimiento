<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic\Consumo;

use App\Domain\Telematic\Consumo\IndicadoresConsumo;
use App\Domain\Telematic\Consumo\IntervaloConsumo;
use App\Domain\Telematic\Consumo\PoliticaAtribucion;
use App\Domain\Telematic\Consumo\PoliticaIndicadores;
use App\Domain\Telematic\Consumo\PoliticaSegmentacion;
use App\Domain\Telematic\Consumo\ReglaSegmentacion;
use App\Domain\Telematic\Consumo\SerieConsumo;
use App\Domain\Telematic\Consumo\ViajeSegmentado;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * l/100 km y l/h de motor sobre un viaje.
 *
 * Los dos indicadores **no son simétricos**, y esa asimetría es el motivo de que
 * vivan separados en vez de calcularse juntos:
 *
 * - **l/100 km** sale de kilómetros y litros. No necesita duración, así que un
 *   hueco de dos horas no lo invalida: si el odómetro subió 200 y el tanque
 *   bajó 60, el ratio es real aunque no sepamos cuándo pasó.
 *
 * - **l/h de motor** necesita las horas de motor del proveedor, y ese campo se
 *   reporta mal en parte de la flota. En producción se vio un equipo con
 *   1.038.298 km y 3.726,9 h, que da 278 km/h de media: físicamente imposible
 *   con el motor encendido. Cuando las horas no son creíbles se descarta **el
 *   indicador**, no el viaje.
 *
 * @internal
 */
final class IndicadoresConsumoTest extends TestCase
{
    public function testElConsumoPorCienKilometrosSaleDeLitrosYKilometros(): void
    {
        $indicadores = $this->indicadores($this->ronda(km: 300.0, litros: 111.0, decimas: 30));

        self::assertEqualsWithDelta(37.0, (float) $indicadores->litrosPor100Km(), 0.01);
        self::assertNull($indicadores->motivoSinPor100Km());
    }

    public function testElConsumoPorCienKilometrosNoDependeDeLaDuracion(): void
    {
        // Con un hueco de dos horas, el odómetro y el tanque siguen diciendo la
        // verdad. El ratio por distancia no se invalida.
        $indicadores = $this->indicadores($this->rondaConHueco(300.0, 111.0, 30));

        self::assertEqualsWithDelta(37.0, (float) $indicadores->litrosPor100Km(), 0.01);
    }

    public function testSinDesplazamientoNoHayConsumoPorCienKilometros(): void
    {
        // La segmentación nunca arma un viaje sin movimiento: un equipo parado
        // no es un trayecto. La guarda del indicador existe igual, y se prueba
        // construyendo el viaje a mano en vez de a través de la regla.
        $instante = new DateTimeImmutable('2026-10-10 06:00:00');
        $siguiente = $instante->modify('+15 minutes');

        $viaje = new ViajeSegmentado(
            [
                IntervaloConsumo::entre(
                    $this->muestra($instante, 487000, 500.0, 40625),
                    $this->muestra($siguiente, 487000, 480.0, 40640),
                    new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 20.0),
                ),
            ],
            5,
        );

        $indicadores = $this->indicadores($viaje);

        self::assertNull($indicadores->litrosPor100Km());
        self::assertStringContainsString('kilómetros', (string) $indicadores->motivoSinPor100Km());
    }

    public function testUnEquipoParadoNoGeneraViaje(): void
    {
        // La regla de segmentación es la primera barrera: sin desplazamiento
        // no hay viaje, y entonces no hay nada que indicar.
        $instante = new DateTimeImmutable('2026-10-10 06:00:00');

        $muestras = [
            $this->muestra($instante, 487000, 500.0, 40625),
            $this->muestra($instante->modify('+15 minutes'), 487000, 480.0, 40640),
            $this->muestra($instante->modify('+30 minutes'), 487000, 460.0, 40655),
            $this->muestra($instante->modify('+45 minutes'), 487000, 440.0, 40670),
        ];

        $viajes = (new ReglaSegmentacion(new PoliticaSegmentacion(kmMinimosViaje: 0, intervalosParadoParaCerrar: 2)))
            ->segmentar($this->serie($muestras));

        self::assertSame([], $viajes);
    }

    public function testSinSensorDeCombustibleNoHayNingunIndicador(): void
    {
        $indicadores = $this->indicadores($this->ronda(km: 300.0, litros: null, decimas: 30));

        self::assertNull($indicadores->litrosPor100Km());
        self::assertNull($indicadores->litrosPorHoraMotor());
        self::assertStringContainsString('combustible', (string) $indicadores->motivoSinPor100Km());
    }

    public function testElConsumoPorHoraUsaHorasDeMotorYNadaMas(): void
    {
        // 111 l en 3 h de motor da 37 l/h, con 100 km/h implícitos: dentro de
        // la banda plausible.
        $indicadores = $this->indicadores($this->ronda(km: 300.0, litros: 111.0, decimas: 30));

        self::assertEqualsWithDelta(37.0, (float) $indicadores->litrosPorHoraMotor(), 0.01);
        self::assertNull($indicadores->motivoSinPorHora());
    }

    public function testUnaVelocidadImposibleInvalidaSoloElConsumoPorHora(): void
    {
        // 300 km en 0,4 h de motor implica 750 km/h. Ningún camión recorre eso con el
        // motor encendido, así que las horas no son creíbles.
        $indicadores = $this->indicadores($this->ronda(km: 300.0, litros: 111.0, decimas: 4));

        self::assertEqualsWithDelta(
            37.0,
            (float) $indicadores->litrosPor100Km(),
            0.01,
            'El ratio por distancia sigue valiendo aunque las horas sean basura.',
        );
        self::assertNull($indicadores->litrosPorHoraMotor());
        self::assertStringContainsString('750', (string) $indicadores->motivoSinPorHora());
    }

    public function testUnaVelocidadDemasiadoBajaTambienInvalidaElConsumoPorHora(): void
    {
        // 300 km en 600 h de motor son 0,5 km/h: el contador de horas está
        // corrido al revés. Por debajo del piso también se descarta.
        $indicadores = $this->indicadores($this->ronda(km: 300.0, litros: 111.0, decimas: 6000));

        self::assertNull($indicadores->litrosPorHoraMotor());
        self::assertNotNull($indicadores->motivoSinPorHora());
        self::assertEqualsWithDelta(37.0, (float) $indicadores->litrosPor100Km(), 0.01);
    }

    public function testSinHorasDeMotorNoHayConsumoPorHora(): void
    {
        $indicadores = $this->indicadores($this->ronda(km: 300.0, litros: 111.0, decimas: null));

        self::assertNull($indicadores->litrosPorHoraMotor());
        self::assertStringContainsString('horas de motor', (string) $indicadores->motivoSinPorHora());
        self::assertNotNull($indicadores->litrosPor100Km());
    }

    public function testUnViajeNoConfiableNoCalculaConsumoPorHora(): void
    {
        // Con un hueco, los minutos atribuibles no alcanzan. El ratio por
        // distancia se muestra igual.
        $indicadores = $this->indicadores($this->rondaConHueco(300.0, 111.0, 30));

        self::assertNull($indicadores->litrosPorHoraMotor());
        self::assertStringContainsString('cobertura', (string) $indicadores->motivoSinPorHora());
        self::assertNotNull($indicadores->litrosPor100Km());
    }

    public function testLaBandaPlausibleEsConfigurable(): void
    {
        // 300 km en 3 h de motor son 100 km/h: entra con el tope de 120 que
        // viene por defecto, pero no con un tope de 60.
        $viaje = $this->ronda(km: 300.0, litros: 111.0, decimas: 30);

        $estrecho = new IndicadoresConsumo(
            $viaje,
            new PoliticaIndicadores(velocidadMaximaPlausibleKmh: 60.0, velocidadMinimaPlausibleKmh: 3.0),
        );

        self::assertNull($estrecho->litrosPorHoraMotor());
        self::assertNotNull($estrecho->motivoSinPorHora());
    }

    /**
     * Una ronda recta: kilómetros y consumo repartidos en partes iguales.
     *
     * @param list<float> $kms Kilómetros por intervalo.
     */
    private function ronda(
        float $km = 300.0,
        ?float $litros = 111.0,
        ?int $decimas = 30,
        array $kms = [],
    ): ViajeSegmentado {
        $recorridos = $kms !== [] ? $kms : [$km / 2, $km / 2];
        $cuantos = count($recorridos);

        $litrosPorIntervalo = ($litros ?? 0.0) / $cuantos;
        $decimasPorIntervalo = ($decimas ?? 0) / $cuantos;

        $instante = new DateTimeImmutable('2026-10-10 06:00:00');
        $odometro = 487000;
        $combustible = 500.0;
        $horas = 40625;

        $muestras = [$this->muestra($instante, $odometro, $combustible, $horas)];

        foreach ($recorridos as $recorrido) {
            $instante = $instante->modify('+15 minutes');
            $odometro += (int) round((float) $recorrido);
            $combustible -= $litrosPorIntervalo;
            $horas += (int) round($decimasPorIntervalo);

            $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);
        }

        // Dos muestras paradas para que el viaje cierre.
        $instante = $instante->modify('+15 minutes');
        $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);
        $instante = $instante->modify('+15 minutes');
        $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);

        return $this->primerViaje($muestras);
    }

    /**
     * La misma ronda pero con un hueco de dos horas en el medio.
     */
    private function rondaConHueco(float $km, ?float $litros, ?int $decimas): ViajeSegmentado
    {
        $litrosPorIntervalo = ($litros ?? 0.0) / 3;
        $decimasPorIntervalo = ($decimas ?? 0) / 3;
        $kmPorIntervalo = (int) round($km / 3);

        $instante = new DateTimeImmutable('2026-10-10 06:00:00');
        $odometro = 487000;
        $combustible = 500.0;
        $horas = 40625;

        $muestras = [$this->muestra($instante, $odometro, $combustible, $horas)];

        $avanzar = static function () use (&$instante): void {
            $instante = $instante->modify('+15 minutes');
        };

        // Intervalo 1: 15 minutos, completo.
        $avanzar();
        $odometro += $kmPorIntervalo;
        $combustible -= $litrosPorIntervalo;
        $horas += (int) round($decimasPorIntervalo);
        $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);

        // Intervalo 2: dos horas de hueco.
        $avanzar();
        $instante = $instante->modify('+1 hours');
        $instante = $instante->modify('+45 minutes');
        $odometro += $kmPorIntervalo;
        $combustible -= $litrosPorIntervalo;
        $horas += (int) round($decimasPorIntervalo);
        $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);

        // Intervalo 3: 15 minutos, completo.
        $avanzar();
        $odometro += $kmPorIntervalo;
        $combustible -= $litrosPorIntervalo;
        $horas += (int) round($decimasPorIntervalo);
        $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);

        $avanzar();
        $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);
        $avanzar();
        $muestras[] = $this->muestra($instante, $odometro, $combustible, $horas);

        return $this->primerViaje($muestras);
    }

    /** @param list<MuestraEquipo> $muestras */
    private function primerViaje(array $muestras): ViajeSegmentado
    {
        return (new ReglaSegmentacion(new PoliticaSegmentacion(kmMinimosViaje: 0, intervalosParadoParaCerrar: 2)))
            ->segmentar(new SerieConsumo($muestras, new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 20.0)))[0];
    }

    /** @param list<MuestraEquipo> $muestras */
    private function serie(array $muestras): SerieConsumo
    {
        return new SerieConsumo($muestras, new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 20.0));
    }

    private function indicadores(ViajeSegmentado $viaje): IndicadoresConsumo
    {
        return new IndicadoresConsumo($viaje, new PoliticaIndicadores());
    }

    private function muestra(
        DateTimeImmutable $instante,
        int $odometro,
        ?float $combustible,
        ?int $decimas,
    ): MuestraEquipo {
        return new MuestraEquipo(
            7,
            1,
            42,
            'WIALON-42',
            'wialon',
            new InstantaneaEquipo(
                $instante,
                new Posicion(-32.8051950, -70.0725300, 80.0, 320, 3217.30, 18, $instante),
                $odometro,
                $decimas,
                true,
                false,
                28.52,
                $combustible,
            ),
            $instante,
        );
    }
}