<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Si el intervalo sirve para atribuir algo al tiempo.
 *
 * `Hueco` no invalida el intervalo: los kilómetros y los litros existen igual.
 * Lo que no se puede es repartirlos en el tiempo. Un camión que-reportedó
 * 800 km y 80 litros entre dos capturas separadas por tres días puede haber
 * cargado en el medio, haber pasado por un taller o haber estado parado.
 * El sistema no elige entre esas tres posibilidades: informa que no sabe.
 */
enum CalidadIntervalo: string
{
    case Completo = 'COMPLETO';
    case Hueco = 'HUECO';

    /** Alcanza para contar litros y kilómetros, pero no para calcular l/h. */
    case Parcial = 'PARCIAL';
}