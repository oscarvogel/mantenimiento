<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use DateTimeImmutable;

interface TripClock
{
    public function now(): DateTimeImmutable;
}
