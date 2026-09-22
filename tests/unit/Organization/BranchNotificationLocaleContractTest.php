<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BranchNotificationLocaleContractTest extends TestCase
{
    public function testBranchLocaleOverridesCompanyLocaleWithCompanyFallback(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-22-194700_AddBranchNotificationLocale.php');
        $tenant = file_get_contents(APPPATH . 'Controllers/TenantAdmin.php');
        $administration = file_get_contents(APPPATH . 'Infrastructure/Organization/CodeIgniterTenantAdministration.php');
        $payload = file_get_contents(APPPATH . 'Presentation/AdministrationPayload.php');
        $branches = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/BranchesAdminPage.vue');
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $public = file_get_contents(APPPATH . 'Controllers/PublicEquipmentReadings.php');

        foreach ([$migration, $tenant, $administration, $payload, $branches, $queue, $public] as $source) {
            self::assertIsString($source);
        }

        self::assertStringContainsString('idioma_notificaciones', $migration);
        self::assertStringContainsString("'null' => true", $migration);
        self::assertStringContainsString('in_list[ES,PT]', $tenant);
        self::assertStringContainsString('idioma_notificaciones', $administration);
        self::assertStringContainsString('notificationLocale', $payload);
        self::assertStringContainsString('Usar idioma de la empresa', $branches);
        self::assertStringContainsString('sucursal_idioma_notificaciones', $queue);
        self::assertStringContainsString('$branchLocale !==', $queue);
        self::assertStringContainsString('empresa_idioma_notificaciones', $public);
        self::assertStringContainsString('sucursal_idioma_notificaciones', $public);
        self::assertStringContainsString('equipmentLocale', $public);
    }
}
