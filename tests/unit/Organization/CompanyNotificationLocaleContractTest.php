<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CompanyNotificationLocaleContractTest extends TestCase
{
    public function testCompanyLocaleIsPersistedAndExposed(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-22-190000_AddCompanyNotificationLocale.php');
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $administration = file_get_contents(APPPATH . 'Infrastructure/Organization/CodeIgniterOrganizationAdministration.php');
        $payload = file_get_contents(APPPATH . 'Presentation/AdministrationPayload.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertIsString($migration);
        self::assertIsString($controller);
        self::assertIsString($administration);
        self::assertIsString($payload);
        self::assertIsString($page);

        self::assertStringContainsString('idioma_notificaciones', $migration);
        self::assertStringContainsString("default' => 'ES'", $migration);
        self::assertStringContainsString('in_list[ES,PT]', $controller);
        self::assertStringContainsString('idioma_notificaciones', $administration);
        self::assertStringContainsString('notificationLocale', $payload);
        self::assertStringContainsString('Idioma de avisos y carga pública', $page);
        self::assertStringContainsString('<option value="PT">Português</option>', $page);
    }
}
