<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance\Gps;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La lectura que hoy define el odómetro del equipo: la última vigente cuyo km
 * es el odómetro actual. Es la base sobre la que se suman los km del GPS.
 */
final readonly class LecturaKmVigente
{
    public function __construct(
        public int $equipoId,
        public int $km,
        public DateTimeImmutable $fecha,
    ) {
        if ($equipoId <= 0) {
            throw new InvalidArgumentException('La lectura necesita un equipo válido.');
        }

        if ($km < 0) {
            throw new InvalidArgumentException('El km de la lectura no puede ser negativo.');
        }
    }
}
