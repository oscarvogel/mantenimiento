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

    public function testDemoHourPlansUseDateOffsetsMatchingTheirInterval(): void
    {
        $seeder = file_get_contents(APPPATH . 'Database/Seeds/DemoCompanySeeder.php');

        self::assertIsString($seeder);
        self::assertMatchesRegularExpression("~'VENCIDO_H'.*'-188 days'.*'-8 days'~s", $seeder);
        self::assertMatchesRegularExpression("~'PROXIMO_H'.*'-168 days'.*'\\+12 days'~s", $seeder);
    }
}
