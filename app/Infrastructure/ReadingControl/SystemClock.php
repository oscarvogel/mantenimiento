<?php

declare(strict_types=1);

namespace App\Infrastructure\ReadingControl;

use App\Application\ReadingControl\Port\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Adaptador de reloj de sistema para el control de lecturas.
 *
 * Usa la zona horaria de la aplicación para que "hoy" coincida con la jornada
 * laboral del operador y no con UTC.
 */
final readonly class SystemClock implements Clock
{
    public function __construct(
        private DateTimeZone $timezone = new DateTimeZone('America/Argentina/Buenos_Aires'),
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
