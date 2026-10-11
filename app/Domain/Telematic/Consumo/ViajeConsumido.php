<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Un viaje con sus indicadores ya calculados.
 *
 * Se separan porque el viaje y el indicador tienen vidas distintas: el viaje se
 * deriva de la serie y el indicador se calcula sobre el. Juntarlos en un solo
 * objeto obliga a recalcular uno cuando sólo cambia el otro.
 */
final readonly class ViajeConsumido
{
    public function __construct(
        private ViajeSegmentado $viaje,
        private IndicadoresConsumo $indicadores,
    ) {
    }

    public function viaje(): ViajeSegmentado
    {
        return $this->viaje;
    }

    public function indicadores(): IndicadoresConsumo
    {
        return $this->indicadores;
    }

    public function inicio(): string
    {
        return $this->viaje->inicio()->format('Y-m-d H:i:s');
    }

    public function fin(): string
    {
        return $this->viaje->fin()->format('Y-m-d H:i:s');
    }
}