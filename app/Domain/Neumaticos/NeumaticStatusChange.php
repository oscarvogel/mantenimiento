<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DateTimeImmutable;

/**
 * Una entrada de la bitácora de estados del neumático. Reemplaza a la tabla
 * `historial_estado_cubiertas` del sistema anterior: no se borra ni se edita.
 */
final readonly class NeumaticStatusChange
{
    public function __construct(
        public ?NeumaticStatus $from,
        public NeumaticStatus $to,
        public DateTimeImmutable $at,
        public string $reason,
    ) {
    }
}
