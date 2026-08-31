<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use Config\App;
use DateTimeImmutable;
use DateTimeZone;

final class SystemNotificationClock implements NotificationClock
{
    private readonly DateTimeZone $timezone;

    public function __construct(?string $timezone = null)
    {
        $configuredTimezone = trim($timezone ?? (string) config(App::class)->appTimezone);
        $this->timezone = new DateTimeZone($configuredTimezone);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
