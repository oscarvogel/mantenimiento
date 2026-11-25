<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticClock;
use DateTimeImmutable;

final class SystemNeumaticClock implements NeumaticClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
