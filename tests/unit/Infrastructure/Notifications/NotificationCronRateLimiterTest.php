<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\CodeIgniterNotificationCronRateLimiter;
use CodeIgniter\Throttle\Throttler;
use PHPUnit\Framework\TestCase;

final class NotificationCronRateLimiterTest extends TestCase
{
    public function testRateLimitUsesHashedIpAndConfiguredWindow(): void
    {
        $throttler = $this->createMock(Throttler::class);
        $throttler->expects(self::once())
            ->method('check')
            ->with(
                self::callback(static function (string $key): bool {
                    return str_starts_with($key, 'notification_cron_')
                        && ! str_contains($key, '192.0.2.10')
                        && strlen($key) === strlen('notification_cron_') + 64;
                }),
                6,
                60,
            )
            ->willReturn(false);
        $throttler->method('getTokenTime')->willReturn(23);

        $limiter = new CodeIgniterNotificationCronRateLimiter($throttler, 6, 60);

        self::assertFalse($limiter->allow('192.0.2.10'));
        self::assertSame(23, $limiter->retryAfterSeconds());
    }
}
