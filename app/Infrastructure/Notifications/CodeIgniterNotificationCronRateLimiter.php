<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationCronRateLimiter;
use CodeIgniter\Throttle\Throttler;

final readonly class CodeIgniterNotificationCronRateLimiter implements NotificationCronRateLimiter
{
    public function __construct(
        private Throttler $throttler,
        private int $maximumAttempts,
        private int $windowSeconds,
    ) {
    }

    public function allow(string $ipAddress): bool
    {
        return $this->throttler->check(
            $this->key($ipAddress),
            max(1, $this->maximumAttempts),
            max(1, $this->windowSeconds),
        );
    }

    public function retryAfterSeconds(): int
    {
        return max(1, $this->throttler->getTokenTime());
    }

    private function key(string $ipAddress): string
    {
        return 'notification_cron_' . hash('sha256', $ipAddress);
    }
}
