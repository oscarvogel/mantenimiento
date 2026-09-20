<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SuperAdminMigrationDiagnosticsContractTest extends TestCase
{
    public function testControllerDefinesMigrationDiagnosticsUsedByIndexAndApplyAction(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        self::assertIsString($controller);

        self::assertStringContainsString('$payload[\'migrations\'] = $this->migrationDiagnostics();', $controller);
        self::assertStringContainsString('$before = $this->migrationDiagnostics();', $controller);
        self::assertStringContainsString('private function migrationDiagnostics(): array', $controller);
        self::assertStringContainsString("'target319Registered'", $controller);
        self::assertStringContainsString("'duplicateActiveGroups'", $controller);
        self::assertStringContainsString('scandir($migrationDir)', $controller);
        self::assertStringContainsString("2026-09-18-140500", $controller);
    }
}
