<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Uso;

use DateTimeImmutable;

/**
 * Un período en el que la mitad o más de las unidades de una integración
 * dejaron de reportar a la vez.
 *
 * No dice por qué: puede ser el proveedor, la cuenta, el token o nuestra
 * ingesta. Dice que los datos de ese período no están, para que nadie lea un
 * hueco como "el camión no se movió".
 */
final readonly class CorteIntegracion
{
    public function __construct(
        public DateTimeImmutable $desde,
        public DateTimeImmutable $hasta,
        public int $unidadesAfectadas,
        public int $unidadesTotales,
        public bool $sigueAbierto,
    ) {
    }

    public function horas(): float
    {
        return ($this->hasta->getTimestamp() - $this->desde->getTimestamp()) / 3600;
    }
}
