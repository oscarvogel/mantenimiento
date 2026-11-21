<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance\Gps;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un valor del odómetro calculado por el GPS en un instante.
 *
 * **No es el odómetro del camión.** El GPS acumula distancia desde que se
 * instaló, con otra base: en la flota de la empresa 4 la diferencia con el
 * tablero va de -195 a +3.955 km según el camión. Por eso solo se usan
 * diferencias entre dos valores. Y la diferencia crece con el uso: el GPS
 * cuenta entre 0 y 9% menos km que el tablero, así que la estimación tiende a
 * quedarse corta, nunca a adelantarse de más.
 */
final readonly class OdometroGps
{
    public function __construct(
        public DateTimeImmutable $en,
        public int $km,
    ) {
        if ($km < 0) {
            throw new InvalidArgumentException('El odómetro del GPS no puede ser negativo.');
        }
    }
}
