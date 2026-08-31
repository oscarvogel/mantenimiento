<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface NotificationCronRateLimiter
{
    public function allow(string $ipAddress): bool;

    public function retryAfterSeconds(): int;
}
