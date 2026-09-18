<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppPilotModeContractTest extends TestCase
{
    public function testPilotConfigurationIsPersistedAndExposed(): void
    {
        $store = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterGlobalNotificationSettingsStore.php');
        $controller = file_get_contents(APPPATH . 'Controllers/NotificationSettings.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/NotificationSettingsPage.vue');

        self::assertIsString($store);
        self::assertIsString($controller);
        self::assertIsString($page);

        self::assertStringContainsString("'whatsapp_pilot_enabled'", $store);
        self::assertStringContainsString("'whatsapp_pilot_phone'", $store);
        self::assertStringContainsString("'whatsAppPilotEnabled'", $controller);
        self::assertStringContainsString("'whatsAppPilotPhone'", $controller);
        self::assertStringContainsString('name="whatsapp_pilot_enabled"', $page);
        self::assertStringContainsString('name="whatsapp_pilot_phone"', $page);
    }

    public function testAutomaticQueueRedirectsToPilotPhoneBeforeCreatingPendingDelivery(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');

        self::assertIsString($queue);
        self::assertStringContainsString("$pilotEnabled = (bool) (\$settings['whatsapp_pilot_enabled'] ?? true);", $queue);
        self::assertStringContainsString("$pilotPhone = \$this->gateway->normalizePhone", $queue);
        self::assertStringContainsString("$phone = \$pilotEnabled ? \$pilotPhone : \$realPhone;", $queue);
        self::assertStringContainsString('PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL', $queue);
        self::assertStringContainsString('Modo piloto activo pero no hay un teléfono piloto válido configurado.', $queue);
    }

    public function testPilotMigrationDefaultsToSafeEnabledState(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-18-191500_AddWhatsAppPilotSettings.php');

        self::assertIsString($migration);
        self::assertStringContainsString("'whatsapp_pilot_enabled'", $migration);
        self::assertStringContainsString("'default' => 1", $migration);
        self::assertStringContainsString("'whatsapp_pilot_phone'", $migration);
    }
}
