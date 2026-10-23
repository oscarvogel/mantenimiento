<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OperationalTripsCreateFormContractTest extends TestCase
{
    public function testCreateFormReadsOldInputFromSessionWithoutCallingOldWithoutAKey(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/OperationalTrips.php');

        self::assertStringNotContainsString('old()', $controller);
        self::assertStringContainsString("session('_ci_old_input')", $controller);
        self::assertStringContainsString("\$oldInput['post']", $controller);
    }
}
