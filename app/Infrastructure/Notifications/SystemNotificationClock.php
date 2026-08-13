<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use DateTimeImmutable;

final class SystemNotificationClock implements NotificationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
