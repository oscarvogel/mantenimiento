<?php

declare(strict_types=1);

namespace App\Application\ReadingControl\Port;

use DateTimeImmutable;

/**
 * Puerto de reloj del contexto de control de lecturas.
 *
 * Permite calcular antigüedad de lecturas de forma determinista y probar los
 * filtros `today`, `not_today`, `gt_3` y `gt_7` sin depender del reloj global.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
