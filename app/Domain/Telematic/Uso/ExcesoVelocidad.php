<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Uso;

use DateTimeImmutable;

/** Un tramo de muestras seguidas por encima del umbral de velocidad. */
final readonly class ExcesoVelocidad
{
    public function __construct(
        public DateTimeImmutable $inicio,
        public DateTimeImmutable $fin,
        public float $velocidadMaximaKmh,
        public int $muestras,
        public ?float $latitud,
        public ?float $longitud,
    ) {
    }
}
