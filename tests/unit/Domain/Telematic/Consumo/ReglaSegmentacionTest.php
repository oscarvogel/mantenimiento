<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic\Consumo;

use App\Domain\Telematic\Consumo\CalidadIntervalo;
use App\Domain\Telematic\Consumo\ClasificacionIntervalo;
use App\Domain\Telematic\Consumo\IntervaloConsumo;
use App\Domain\Telematic\Consumo\PoliticaAtribucion;
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
 * Derivar viajes de una serie de capturas.
 *
 * El viaje es una **hipótesis** sobre la serie, no un dato: se deriva cada vez
 * que se lee y no se persiste, justamente para que una mejora de cobertura
 * pueda corregir meses de resultados sin dejar números viejos dando vueltas.
 *
 * Lo que más cuesta honestidad acá es el caso de los trayectos con un solo
 * intervalo observado: no se pueden reconstruir sus extremos. No se estima.
 * El viaje sale marcado como no reconstruible y el sistema dice por qué.
 *
 * @internal
 */
final class ReglaSegmentacionTest extends TestCase
{
    private const ATRIBUCION = [
        'deltaMinimoLitros' => 2.0,
        'umbralCargaLitros' => 20.0,
        'huecoMaximoMinutos' => 30,
    ];

    private const SEGMENTACION = [
        'kmMinimosViaje' => 1,
        'minutosMinimosViaje' => 5,
        'intervalosParadoParaCerrar' => 2,
    ];

    public function testUnaRondaSostenidaFormaUnViaje(): void
    {
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: 487000, moviendo: false, motor: false),
            $this->muestra('2026-10-10 06:15:00', km: 487000, moviendo: true, motor: true),
            $this->muestra('2026-10-10 06:30:00', km: 487090, moviendo: true, motor: true),
            $this->muestra('2026-10-10 06:45:00', km: 487180, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 07:00:00', 487180),
            $this->muestraParada('2026-10-10 07:15:00', 487180),
        ]);

        self::assertCount(1, $viajes);

        $viaje = $viajes[0];
        self::assertSame('2026-10-10 06:15:00', $viaje->inicio()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-10 06:45:00', $viaje->fin()->format('Y-m-d H:i:s'));
        self::assertSame(180, $viaje->kilometraje());
        self::assertSame(30, $viaje->duracionMinutos());
        self::assertTrue($viaje->reconstruible());
    }

    public function testUnaManiobraNoEsUnViaje(): void
    {
        // Dos capturas moviéndose 200 metros no son un viaje: es un
        // adelantamiento en un semáforo. Sin el mínimo de kilómetros, cada
        // maniobra de estacionamiento sería una fila en el reporte.
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: 487000, moviendo: false, motor: false),
            $this->muestra('2026-10-10 06:15:00', km: 487000, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:30:00', 487000, 0.2),
            $this->muestraParada('2026-10-10 06:45:00', 487000),
        ]);

        self::assertSame([], $viajes, 'Menos de 1 km no es un viaje.');
    }

    public function testDosRondasSeparadasSonDosViajes(): void
    {
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: 487000, moviendo: false, motor: false),
            $this->muestra('2026-10-10 06:15:00', km: 487100, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:30:00', 487100),
            $this->muestraParada('2026-10-10 06:45:00', 487100),
            $this->muestra('2026-10-10 07:00:00', km: 487100, moviendo: false, motor: false),
            $this->muestra('2026-10-10 07:15:00', km: 487250, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 07:30:00', 487250),
            $this->muestraParada('2026-10-10 07:45:00', 487250),
        ]);

        self::assertCount(2, $viajes);
        self::assertSame(100, $viajes[0]->kilometraje());
        self::assertSame(150, $viajes[1]->kilometraje());
    }

    public function testLosViajesNoSeSolapanYOrdenanPorInicio(): void
    {
        $viajes = $this->segmentar([
            $this->muestraParada('2026-10-10 06:00:00', 487000),
            $this->muestra('2026-10-10 06:15:00', km: 487120, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:30:00', 487120),
            $this->muestraParada('2026-10-10 06:45:00', 487120),
            $this->muestra('2026-10-10 07:00:00', km: 487300, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 07:15:00', 487300),
            $this->muestraParada('2026-10-10 07:30:00', 487300),
        ]);

        self::assertCount(2, $viajes);
        self::assertTrue($viajes[0]->fin() < $viajes[1]->inicio(), 'El primer viaje tiene que terminar antes de que empiece el segundo.');
    }

    public function testUnViajeMasCortoQueElMuestreoNoSeReconstruye(): void
    {
        // Una sola captura en movimiento no forma viaje: sin una muestra
        // anterior y otra posterior no se puede saber cuándo empezó ni cuándo
        // terminó. Es el caso de un viaje de ocho minutos, y la respuesta
        // correcta es decirlo, no estimarlo.
        $viajes = $this->segmentar([
            $this->muestraParada('2026-10-10 06:00:00', 487000),
            $this->muestra('2026-10-10 06:15:00', km: 487050, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:30:00', 487050),
            $this->muestraParada('2026-10-10 06:45:00', 487050),
        ]);

        self::assertCount(1, $viajes);
        self::assertFalse(
            $viajes[0]->reconstruible(),
            'Con una sola muestra en movimiento los extremos del viaje son desconocidos.',
        );
        self::assertStringContainsString('no se puede reconstruir', $viajes[0]->motivoNoReconstruible());
    }

    public function testElViajeAcumulaLosLitrosDeSusIntervalosConsumidos(): void
    {
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: 487000, litros: 600.0, moviendo: false, motor: false),
            $this->muestra('2026-10-10 06:15:00', km: 487100, litros: 585.0, moviendo: true, motor: true),
            $this->muestra('2026-10-10 06:30:00', km: 487200, litros: 572.0, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:45:00', 487200, 572.0),
            $this->muestraParada('2026-10-10 07:00:00', 487200, 572.0),
        ]);

        self::assertCount(1, $viajes);
        // 15 l del primer intervalo + 13 del segundo.
        self::assertEqualsWithDelta(28.0, $viajes[0]->litrosConsumidos(), 0.0005);
    }

    public function testUnaCargaDentroDelViajeNoSeCuentaComoConsumo(): void
    {
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: 487000, litros: 600.0, moviendo: false, motor: false),
            $this->muestra('2026-10-10 06:15:00', km: 487100, litros: 585.0, moviendo: true, motor: true),
            // Carga a mitad de camino: el tanque sube 320 l.
            $this->muestra('2026-10-10 06:30:00', km: 487200, litros: 905.0, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:45:00', 487200, 905.0),
            $this->muestraParada('2026-10-10 07:00:00', 487200, 905.0),
        ]);

        self::assertCount(1, $viajes);

        $viaje = $viajes[0];
        self::assertTrue($viaje->huboCarga());
        self::assertEqualsWithDelta(320.0, $viaje->litrosCargados(), 0.0005);
        // El intervalo cargado no aporta consumo: el depósito volvió a subir.
        self::assertEqualsWithDelta(15.0, $viaje->litrosConsumidos(), 0.0005);
    }

    public function testUnaParadaDeUnIntervaloNoParteElViajeEnDos(): void
    {
        // El equipo frena un instante y sigue. Es una parada en la ruta, no el fin
        // del viaje: si el cierre fuera con un solo intervalo sin movimiento,
        // cada semáforo y cada estación de servicio partirían la rodada en
        // trozos que no existen.
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: 487000, moviendo: true, motor: true),
            $this->muestra('2026-10-10 06:15:00', km: 487100, moviendo: true, motor: true),
            $this->muestra('2026-10-10 06:30:00', km: 487200, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:45:00', 487200),
            $this->muestra('2026-10-10 07:00:00', km: 487300, moviendo: true, motor: true),
            $this->muestra('2026-10-10 07:15:00', km: 487400, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 07:30:00', 487400),
            $this->muestraParada('2026-10-10 07:45:00', 487400),
        ]);

        self::assertCount(1, $viajes, 'Una parada de un intervalo no es el fin del viaje.');
        self::assertSame(400, $viajes[0]->kilometraje());
        self::assertSame('2026-10-10 06:00:00', $viajes[0]->inicio()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-10 07:15:00', $viajes[0]->fin()->format('Y-m-d H:i:s'));
    }

    public function testUnHuecoNoRompeElViajePeroDejaDeContarSusMinutos(): void
    {
        // El equipoReportó a las 06:30 y otra vez dos horas después. El viaje
        // sigue siendo el mismo, pero esos 120 minutos no son utilizables: en
        // ese tramo pudo cargar o pasar por un taller.
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: 487000, litros: 600.0, moviendo: false, motor: false),
            $this->muestra('2026-10-10 06:15:00', km: 487100, litros: 585.0, moviendo: true, motor: true),
            $this->muestra('2026-10-10 08:15:00', km: 487300, litros: 560.0, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 08:30:00', 487300, 560.0),
            $this->muestraParada('2026-10-10 08:45:00', 487300, 560.0),
        ]);

        self::assertCount(1, $viajes);

        $viaje = $viajes[0];
        // El viaje va de 06:00 a 08:15: dos horas y cuarto de recorrido, de las
        // cuales sólo el primer cuarto de hora es atribuible.
        self::assertSame(135, $viaje->duracionMinutos());
        self::assertSame(15, $viaje->minutosUtilizables(), 'Los 120 del hueco no cuentan.');
        self::assertSame(300, $viaje->kilometraje(), 'Los kilómetros existen aunque haya hueco.');
        self::assertEqualsWithDelta(40.0, $viaje->litrosConsumidos(), 0.0005);
        self::assertFalse($viaje->consumoConfiable());
    }

    public function testUnaSerieVaciaOTodoParadoNoProduceViajes(): void
    {
        self::assertSame([], $this->segmentar([]));

        self::assertSame([], $this->segmentar([
            $this->muestraParada('2026-10-10 06:00:00', 487000),
            $this->muestraParada('2026-10-10 06:15:00', 487000),
            $this->muestraParada('2026-10-10 06:30:00', 487000),
        ]));
    }

    public function testUnaMuestraSinKilometrajeNoEsMovimiento(): void
    {
        // Sin odómetro no se puede afirmar que el camión se movió. Un proveedor que manda posición pero no odómetro no genera viajes.
        $viajes = $this->segmentar([
            $this->muestra('2026-10-10 06:00:00', km: null, moviendo: false, motor: false),
            $this->muestra('2026-10-10 06:15:00', km: null, moviendo: true, motor: true),
            $this->muestra('2026-10-10 06:30:00', km: null, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:45:00', null),
            $this->muestraParada('2026-10-10 07:00:00', null),
        ]);

        self::assertSame([], $viajes);
    }

    public function testLosIntervalosDelViajeSonContiguos(): void
    {
        $viajes = $this->segmentar([
            $this->muestraParada('2026-10-10 06:00:00', 487000, 600.0),
            $this->muestra('2026-10-10 06:15:00', km: 487100, litros: 585.0, moviendo: true, motor: true),
            $this->muestra('2026-10-10 06:30:00', km: 487200, litros: 572.0, moviendo: true, motor: true),
            $this->muestraParada('2026-10-10 06:45:00', 487200, 572.0),
            $this->muestraParada('2026-10-10 07:00:00', 487200, 572.0),
        ]);

        $intervalos = $viajes[0]->intervalos();

        self::assertCount(2, $intervalos);
        self::assertSame(ClasificacionIntervalo::Consumo, $intervalos[0]->clasificacion());
        self::assertSame(ClasificacionIntervalo::Consumo, $intervalos[1]->clasificacion());

        // Y el intervalo del hueco, si lo hubiera, quedaría dentro del viaje.
        $this->assertSame(CalidadIntervalo::Completo, $intervalos[1]->calidad());
    }

    /** @param list<MuestraEquipo> $muestras @return list<ViajeSegmentado> */
    private function segmentar(array $muestras): array
    {
        return (new ReglaSegmentacion(new PoliticaSegmentacion(...self::SEGMENTACION)))
            ->segmentar($this->serie($muestras));
    }

    /**
     * @param  list<MuestraEquipo> $muestras
     * @return SerieConsumo
     */
    private function serie(array $muestras): SerieConsumo
    {
        return new SerieConsumo($muestras, new PoliticaAtribucion(...self::ATRIBUCION));
    }

    private function muestra(
        string $observada,
        ?int $km,
        ?float $litros = 500.0,
        bool $moviendo = true,
        bool $motor = true,
    ): MuestraEquipo {
        return $this->construir($observada, $km, $litros, $moviendo, $motor);
    }

    private function muestraParada(string $observada, ?int $km, ?float $litros = 500.0): MuestraEquipo
    {
        return $this->construir($observada, $km, $litros, false, false);
    }

    private function construir(
        string $observada,
        ?int $km,
        ?float $litros,
        bool $moviendo,
        bool $motor,
    ): MuestraEquipo {
        $instante = new DateTimeImmutable($observada);

        return new MuestraEquipo(
            7,
            1,
            42,
            'WIALON-42',
            'wialon',
            new InstantaneaEquipo(
                $instante,
                new Posicion(-32.8051950, -70.0725300, $moviendo ? 80.0 : 0.0, 320, 3217.30, 18, $instante),
                $km,
                40625,
                $motor,
                false,
                28.52,
                $litros,
            ),
            $instante,
        );
    }
}
