<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Telematic\CalcularConsumoPorEquipo;
use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\Consumo\PoliticaConsumo;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Lector en memoria que filtra por empresa, equipo y ventana. */
final class SerieEnMemoria implements SerieMuestrasReader
{
    /** @var list<array{empresa:int, equipo:int, desde:string}> */
    public array $consultas = [];

    /** @param list<MuestraEquipo> $muestras */
    public function __construct(private array $muestras)
    {
    }

    public function serie(
        int $empresaId,
        int $equipoId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array {
        $this->consultas[] = [
            'empresa' => $empresaId,
            'equipo' => $equipoId,
            'desde' => $desde->format('Y-m-d H:i:s'),
        ];

        return array_values(array_filter(
            $this->muestras,
            static fn (MuestraEquipo $m): bool => $m->empresaId() === $empresaId
                && $m->equipoId() === $equipoId
                && $m->observadaEn() >= $desde
                && $m->observadaEn() <= $hasta,
        ));
    }
}

/**
 * El recorrido completo: serie -> intervalos -> viajes -> indicadores.
 *
 * Lo que importa acá es que el caso de uso **no escribe** y que el reporte sale
 * marcado como no calibrado mientras los umbrales sean los de fábrica. Con datos
 * de fábrica no hay forma de verificar un número, y presentarlo como si
 * estuviera medido sería mentir por omisión.
 *
 * @internal
 */
final class CalcularConsumoPorEquipoTest extends TestCase
{
    public function testDevuelveElReporteConLaCoberturaYLosUmbralesAplicados(): void
    {
        $reporte = $this->calcular($this->ronda());

        self::assertSame(4, $reporte->muestrasLeidas());
        self::assertCount(1, $reporte->viajes());
        self::assertSame(300, $reporte->kilometrajeTotal());
        self::assertEqualsWithDelta(111.0, $reporte->litrosTotales(), 0.01);
        self::assertTrue($reporte->cobertura()->suficiente());
        self::assertArrayHasKey('umbralCargaLitros', $reporte->umbrales());
    }

    public function testElReporteSaleMarcadoComoNoCalibradoConLosDefaults(): void
    {
        self::assertTrue(
            $this->calcular($this->ronda())->sinCalibrar(),
            'Con umbrales de fábrica nadie verificó los números: hay que decirlo.',
        );
    }

    public function testUnaPoliticaCalibradaDejaDeMarcarElReporte(): void
    {
        $politica = new PoliticaConsumo(
            PoliticaConsumo::porDefecto()->atribucion,
            PoliticaConsumo::porDefecto()->segmentacion,
            PoliticaConsumo::porDefecto()->cobertura,
            PoliticaConsumo::porDefecto()->indicadores,
            true,
        );

        $reporte = (new CalcularConsumoPorEquipo(
            new SerieEnMemoria($this->ronda()),
            $politica,
        ))->execute(7, 42, $this->desde(), $this->hasta());

        self::assertFalse($reporte->sinCalibrar());
    }

    public function testLejosARangoDelEquipoNoTraeNada(): void
    {
        $reporte = $this->calcular([]);

        self::assertSame(0, $reporte->muestrasLeidas());
        self::assertSame([], $reporte->viajes());
        self::assertFalse($reporte->cobertura()->suficiente());
        self::assertStringContainsString('Sin datos', (string) $reporte->cobertura()->motivoInsuficiente());
    }

    public function testElPeriodoSePideConEmpresaEquipoYVentana(): void
    {
        $lector = new SerieEnMemoria($this->ronda());

        (new CalcularConsumoPorEquipo($lector))->execute(7, 42, $this->desde(), $this->hasta());

        self::assertCount(1, $lector->consultas);
        self::assertSame(7, $lector->consultas[0]['empresa']);
        self::assertSame(42, $lector->consultas[0]['equipo']);
    }

    public function testLasCapturasDeOtraEmpresaNoSeMezclan(): void
    {
        // Mismo equipo, misma hora, otra empresa. Si el filtro por empresa
        // fallara, el odómetro retrocedería y el consumo del período sería
        // negativo sin ningún error visible.
        $propias = $this->ronda(empresaId: 7, equipoId: 42);
        $ajenas = $this->ronda(empresaId: 9, equipoId: 42);

        $reporte = $this->calcular(array_merge($propias, $ajenas));

        self::assertSame(4, $reporte->muestrasLeidas(), 'Sólo las de la empresa pedida.');
        self::assertGreaterThan(0, $reporte->litrosTotales());
    }

    public function testUnaSerieInsuficienteNoSeConfundeConUnaSinConsumo(): void
    {
        // Una sola captura: no hay intervalos, no hay viajes y no hay consumo.
        // Es distinto de un equipo que consumió de verdad y no se movió.
        $reporte = $this->calcular([$this->muestra('2026-10-10 06:00:00', 487000, 500.0, 40625)]);

        self::assertSame([], $reporte->viajes());
        self::assertSame(0.0, $reporte->litrosTotales());
        self::assertFalse($reporte->cobertura()->suficiente());
        self::assertSame(1, $reporte->muestrasLeidas(), 'Había una muestra: no es lo mismo que no tener nada.');
    }

    public function testLosViajesSinConsumoSeCuentanApartados(): void
    {
        $reporte = $this->calcular($this->ronda(litros: null));

        self::assertSame(1, $reporte->viajesSinConsumo());
        self::assertStringContainsString(
            'combustible',
            (string) $reporte->viajes()[0]->indicadores()->motivoSinPor100Km(),
        );
    }

    private function calcular(array $muestras): \App\Domain\Telematic\Consumo\ReporteConsumo
    {
        return (new CalcularConsumoPorEquipo(new SerieEnMemoria($muestras)))
            ->execute(7, 42, $this->desde(), $this->hasta());
    }

    private function desde(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-10 06:00:00');
    }

    private function hasta(): DateTimeImmutable
    {
        // La serie cubre 06:00 a 06:45. Una ventana hasta las 07:00 deja 45
        // minutos cubiertos de 60, con una cola de 15: suficiente.
        return new DateTimeImmutable('2026-10-10 07:00:00');
    }

    /** @return list<MuestraEquipo> */
    private function ronda(int $empresaId = 7, int $equipoId = 42, ?float $litros = 111.0): array
    {
        $litrosPorIntervalo = ($litros ?? 0.0) / 3;
        $instante = $this->desde();
        $odometro = 487000;
        $combustible = 500.0;
        $horas = 40625;

        $muestras = [$this->muestra($instante->format('Y-m-d H:i:s'), $odometro, $combustible, $horas, $empresaId, $equipoId)];

        for ($i = 0; $i < 3; $i++) {
            $instante = $instante->modify('+15 minutes');
            $odometro += 100;
            $combustible -= $litrosPorIntervalo;
            $horas += 15;

            $muestras[] = $this->muestra($instante->format('Y-m-d H:i:s'), $odometro, $combustible, $horas, $empresaId, $equipoId);
        }

        return $muestras;
    }

    private function muestra(
        string $observada,
        int $odometro,
        ?float $combustible,
        ?int $decimas,
        int $empresaId = 7,
        int $equipoId = 42,
    ): MuestraEquipo {
        $instante = new DateTimeImmutable($observada);

        return new MuestraEquipo(
            $empresaId,
            1,
            $equipoId,
            'WIALON-' . $equipoId,
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