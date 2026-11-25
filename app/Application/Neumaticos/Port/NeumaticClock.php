<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use DateTimeImmutable;

interface NeumaticClock
{
    public function now(): DateTimeImmutable;
}
