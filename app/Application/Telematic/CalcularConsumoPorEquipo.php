<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\SerieMuestrasReader;
use App\Domain\Telematic\Consumo\CoberturaPeriodo;
use App\Domain\Telematic\Consumo\DistribucionCombustible;
use App\Domain\Telematic\Consumo\IndicadoresConsumo;
use App\Domain\Telematic\Consumo\IntervaloConsumo;
use App\Domain\Telematic\Consumo\PoliticaCobertura;
use App\Domain\Telematic\Consumo\PoliticaConsumo;
use App\Domain\Telematic\Consumo\ReglaSegmentacion;
use App\Domain\Telematic\Consumo\ReporteConsumo;
use App\Domain\Telematic\Consumo\SerieConsumo;
use App\Domain\Telematic\Consumo\ViajeConsumido;
use App\Domain\Telematic\Consumo\ViajeSegmentado;
use DateTimeImmutable;

/**
 * Lee la serie de un equipo y calcula su consumo por viaje.
 *
 * Es una lectura pura: no escribe nada, no toca la base y no depende del reloj
 * del proceso — la ventana temporal la define quien llama. Eso es lo que
 * permite ver el mismo período dos veces y obtener exactamente lo mismo.
 *
 * El recorrido es el mismo que el de la explicación que le dimos al dominio:
 *
 *     serie -> SerieConsumo (ordena) -> intervalos -> viajes -> indicadores
 *                                                \-> cobertura del período
 *
 * La cobertura no se pide después ni se recalcula aparte: sale de los mismos
 * intervalos, y es la que decide si el período tiene suficiente para mostrar
 * algo.
 */
final readonly class CalcularConsumoPorEquipo
{
    private PoliticaConsumo $politica;

    public function __construct(
        private SerieMuestrasReader $reader,
        ?PoliticaConsumo $politica = null,
    ) {
        // Los defaults son provisorios: sirven para arrancar y para que un
        // diagnóstico pueda ejecutarse sin configuración, pero el reporte sale
        // marcado como sin calibrar.
        $this->politica = $politica ?? PoliticaConsumo::porDefecto();
    }

    public function execute(
        int $empresaId,
        int $equipoId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): ReporteConsumo {
        $muestras = $this->reader->serie($empresaId, $equipoId, $desde, $hasta);

        $serie = new SerieConsumo($muestras, $this->politica->atribucion);
        $viajes = (new ReglaSegmentacion($this->politica->segmentacion))->segmentar($serie);

        return new ReporteConsumo(
            $equipoId,
            $this->conIndicadores($viajes),
            $this->cobertura($serie->intervalos(), $desde, $hasta),
            count($muestras),
            $this->politica->valores(),
            ! $this->politica->calibrada,
            DistribucionCombustible::desde($serie->intervalos()),
        );
    }

    /**
     * @param  list<ViajeSegmentado> $viajes
     * @return list<ViajeConsumido>
     */
    private function conIndicadores(array $viajes): array
    {
        $indicadores = $this->politica->indicadores;

        return array_map(
            static fn (ViajeSegmentado $viaje): ViajeConsumido => new ViajeConsumido(
                $viaje,
                new IndicadoresConsumo($viaje, $indicadores),
            ),
            $viajes,
        );
    }

    /** @param list<IntervaloConsumo> $intervalos */
    private function cobertura(array $intervalos, DateTimeImmutable $desde, DateTimeImmutable $hasta): CoberturaPeriodo
    {
        return $this->politica->cobertura->evaluar($intervalos, $desde, $hasta);
    }
}