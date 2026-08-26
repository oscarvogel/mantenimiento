<?php

declare(strict_types=1);

use App\Application\Notifications\CollectOperationalNotifications;
use Config\Services;
use PHPUnit\Framework\TestCase;

final class NotificationServiceWiringTest extends TestCase
{
    public function testOperationalNotificationCollectorCanBeBuiltFromTheServiceContainer(): void
    {
        $collector = Services::operationalNotificationCollector(false);

        self::assertInstanceOf(CollectOperationalNotifications::class, $collector);
    }
}
