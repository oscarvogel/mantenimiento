<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationCronResponseContractTest extends TestCase
{
    public function testCronControllerRedactsOperationalResponseAndErrors(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/NotificationCron.php');

        self::assertIsString($controller);
        self::assertStringContainsString("'status' => 'ok'", $controller);
        self::assertStringContainsString("'collected'", $controller);
        self::assertStringContainsString("'sent'", $controller);
        self::assertStringContainsString("'retry'", $controller);
        self::assertStringContainsString('setJSON($summary)', $controller);
        self::assertStringNotContainsString("'result' =>", $controller);
        self::assertStringNotContainsString("'email' =>", $controller);
        self::assertStringNotContainsString('SMTP', $controller);
        self::assertStringNotContainsString("'token' =>", $controller);
        self::assertStringNotContainsString("'payload' =>", $controller);
        self::assertStringNotContainsString('$exception->getMessage()', $controller);
        self::assertStringNotContainsString('SHOW_DEBUG_BACKTRACE', $controller);
    }
}
