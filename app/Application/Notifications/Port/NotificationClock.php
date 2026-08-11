<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use DateTimeImmutable;

interface NotificationClock
{
    public function now(): DateTimeImmutable;
}
