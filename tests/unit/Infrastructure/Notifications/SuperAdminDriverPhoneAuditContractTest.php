<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SuperAdminDriverPhoneAuditContractTest extends TestCase
{
    public function testSuperAdminCanRunDriverPhoneAuditWithoutDispatchingWhatsApp(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $notifier = file_get_contents(APPPATH . 'Application/Notifications/NotifyAdminsMissingDriverPhones.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertIsString($controller);
        self::assertIsString($routes);
        self::assertIsString($notifier);
        self::assertIsString($page);

        self::assertStringContainsString('auditDriverPhones', $controller);
        self::assertStringContainsString("notifyAdminsMissingDriverPhones')->execute(true)", $controller);
        self::assertStringContainsString('No se enviaron WhatsApp a choferes.', $controller);
        self::assertStringContainsString("whatsapp/auditar-celulares", $routes);
        self::assertStringContainsString('public function execute(bool $force = false)', $notifier);
        self::assertStringContainsString("Responsable de mantenimiento", $notifier);
        self::assertStringContainsString('auditDriverPhonesAction', $controller);
        self::assertStringContainsString('Auditar celulares ahora', $page);
        self::assertStringContainsString('No envía WhatsApp a choferes', $page);
    }
}
