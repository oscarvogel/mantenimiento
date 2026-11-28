<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationDeliveryQueueContractTest extends TestCase
{
    public function testEveryScheduleDeclarationAcceptsTheOptionalDeliveryEmail(): void
    {
        $sources = [
            APPPATH . 'Application/Notifications/Port/NotificationDeliveryQueue.php',
            APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php',
            __DIR__ . '/NotificationUseCasesTest.php',
            __DIR__ . '/WebPushOperationalTest.php',
            __DIR__ . '/RunNotificationCycleStagesTest.php',
            __DIR__ . '/NotificationEmailDispatchTest.php',
            __DIR__ . '/NotificationDispatchEligibilityTest.php',
            __DIR__ . '/NotificationDispatchCancellationTest.php',
            __DIR__ . '/ManagementReportExpirationSeparationTest.php',
        ];

        foreach ($sources as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, $path);
            self::assertStringContainsString(
                'NotificationPreference $preference, ?string $deliveryEmail = null): void',
                $source,
                $path,
            );
        }
    }

    public function testTheLogicalDeliveryKeyDoesNotIncludeTheRecipient(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php');
        self::assertIsString($source);
        self::assertStringContainsString('{$eventKey}:usuario:{$userId}:email', $source);
        self::assertStringContainsString('{$eventKey}:usuario:{$userId}:push', $source);
    }
}
