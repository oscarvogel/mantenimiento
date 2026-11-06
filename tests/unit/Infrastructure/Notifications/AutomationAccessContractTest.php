<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AutomationAccessContractTest extends TestCase
{
    public function testAutomationsEntryIsReadOnlyAndRequiresItsOwnPermission(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        self::assertIsString($routes);

        self::assertStringContainsString(
            "\$routes->get('automatizaciones', 'Automations::index', ['filter' => ['auth', 'permission:automatizaciones.ver']]);",
            $routes,
        );
        self::assertStringNotContainsString("\$routes->post('automatizaciones", $routes);
        self::assertStringNotContainsString("\$routes->match(['get', 'post'], 'automatizaciones", $routes);
    }

    public function testAutomationPageHasNoSendExecuteOrRetryActions(): void
    {
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/automations/AutomationsPage.vue');
        self::assertIsString($page);

        self::assertStringContainsString('Sólo lectura', $page);
        self::assertStringNotContainsString('<form', $page);
        self::assertStringNotContainsString('<button', $page);
        self::assertStringNotContainsString('method="post"', $page);
    }

    public function testTenantReaderDoesNotUseGlobalExecutionTables(): void
    {
        $reader = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterAutomationOverviewReader.php');
        self::assertIsString($reader);

        self::assertStringNotContainsString('ejecuciones_programadas', $reader);
        self::assertStringNotContainsString('bloqueos_proceso', $reader);
    }

    public function testAutomationMigrationAssignsExplicitRoles(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-11-05-120000_AddAutomationViewPermission.php');
        self::assertIsString($migration);

        self::assertStringContainsString("'automatizaciones.ver'", $migration);
        self::assertStringContainsString("['Administrador', 'Responsable de mantenimiento']", $migration);
        self::assertStringContainsString("table('rol_permisos')", $migration);
    }
}
