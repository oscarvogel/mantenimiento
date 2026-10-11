<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Las cuatro políticas de consumo, juntas, con su bandera de calibración.
 *
 * El bundle existe por una razón de honestidad que ningún cálculo individual
 * puede cubrir: **mientras `calibrada` sea falsa, los números que salen de acá
 * son provisorios**, y eso tiene que llegar hasta la pantalla.
 *
 * El motivo es concreto. Los umbrales por defecto son razonables pero no
 * medidos: no hay forma de calibrarlos contra telemetría real hasta que esto
 * esté en producción y el cron haya acumulado días. Mostrar "37 l/100 km" con
 * la misma seguridad que un dato medido sería mentir por omisión, sobre todo
 * porque el error posible no es pequeño: si el sensor de un camión fluctúa 15
 * litros entre lecturas, sus viajes aparecen con consumos inventados.
 *
 * `calibrada` pasa a verdadera cuando la empresa configura los umbrales con
 * valores propios, en la Tarea 12.
 */
final readonly class PoliticaConsumo
{
    public function __construct(
        public PoliticaAtribucion $atribucion,
        public PoliticaSegmentacion $segmentacion,
        public PoliticaCobertura $cobertura,
        public PoliticaIndicadores $indicadores,
        public bool $calibrada = false,
    ) {
    }

    /** Defaults de arranque. **Provisionales hasta que la empresa los configure.** */
    public static function porDefecto(): self
    {
        return new self(
            new PoliticaAtribucion(deltaMinimoLitros: 2.0, umbralCargaLitros: 20.0, huecoMaximoMinutos: 30),
            new PoliticaSegmentacion(kmMinimosViaje: 1, minutosMinimosViaje: 5, intervalosParadoParaCerrar: 2),
            new PoliticaCobertura(coberturaMinima: 0.70, huecoMaximoMinutos: 30),
            new PoliticaIndicadores(velocidadMaximaPlausibleKmh: 120.0, velocidadMinimaPlausibleKmh: 3.0),
            false,
        );
    }

    /** Los valores aplicados, para que un diagnóstico pueda imprimirlos. */
    public function valores(): array
    {
        return [
            'deltaMinimoLitros' => $this->atribucion->deltaMinimoLitros,
            'umbralCargaLitros' => $this->atribucion->umbralCargaLitros,
            'huecoMaximoAtribucion' => $this->atribucion->huecoMaximoMinutos,
            'kmMinimosViaje' => $this->segmentacion->kmMinimosViaje,
            'minutosMinimosViaje' => $this->segmentacion->minutosMinimosViaje,
            'intervalosParadoParaCerrar' => $this->segmentacion->intervalosParadoParaCerrar,
            'coberturaMinima' => $this->cobertura->coberturaMinima,
            'huecoMaximoCobertura' => $this->cobertura->huecoMaximoMinutos,
            'velocidadMinimaPlausibleKmh' => $this->indicadores->velocidadMinimaPlausibleKmh,
            'velocidadMaximaPlausibleKmh' => $this->indicadores->velocidadMaximaPlausibleKmh,
        ];
    }
}