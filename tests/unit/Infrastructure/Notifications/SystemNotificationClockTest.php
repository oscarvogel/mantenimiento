<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\SystemNotificationClock;
use PHPUnit\Framework\TestCase;

final class SystemNotificationClockTest extends TestCase
{
    public function testNowUsesTheConfiguredApplicationTimezone(): void
    {
        $now = (new SystemNotificationClock('America/Argentina/Buenos_Aires'))->now();

        self::assertSame('America/Argentina/Buenos_Aires', $now->getTimezone()->getName());
    }
}
