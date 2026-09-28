<?php

declare(strict_types=1);

namespace Tests\Support\ReadingControl;

use App\Application\ReadingControl\Port\Clock;
use DateTimeImmutable;

/**
 * Reloj fijo para las pruebas del control de lecturas.
 *
 * Permite verificar los límites de "hoy" y "más de N días" sin depender del
 * reloj global ni de la zona horaria del proceso.
 */
final class ReadingControlClockFake implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
