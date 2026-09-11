<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GlobalNotificationSettingsContractTest extends TestCase
{
    public function testSettingsAreProtectedBySuperadminRoutesAndSecretsAreNotRendered(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = file_get_contents(APPPATH . 'Controllers/NotificationSettings.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/NotificationSettingsPage.vue');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($page);
        self::assertStringContainsString("group('superadmin', ['filter' => 'superadmin']", $routes);
        self::assertStringContainsString("configuracion/notificaciones", $routes);
        self::assertStringNotContainsString("data.settings.smtpPass", $page);
        self::assertStringNotContainsString("data.settings.webPushPrivateKey", $page);
        self::assertStringNotContainsString("data.settings.whatsAppApiKey", $page);
        self::assertStringContainsString('smtpPasswordConfigured', $controller);
        self::assertStringContainsString('webPushPrivateKeyConfigured', $controller);
        self::assertStringContainsString('whatsAppApiKeyConfigured', $controller);
    }

    public function testSecretsAreEncryptedAndEnvironmentRemainsFallback(): void
    {
        $store = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterGlobalNotificationSettingsStore.php');

        self::assertIsString($store);
        self::assertStringContainsString("service('encrypter')->encrypt", $store);
        self::assertStringContainsString("service('encrypter')->decrypt", $store);
        self::assertStringContainsString("env('email.SMTPHost'", $store);
        self::assertStringContainsString("'source' => 'env'", $store);
        self::assertStringContainsString("'source' => 'database'", $store);
    }

    public function testEmailGatewayIsWiredToGlobalSettings(): void
    {
        $services = file_get_contents(APPPATH . 'Config/Services.php');
        $gateway = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterEmailNotificationGateway.php');

        self::assertIsString($services);
        self::assertIsString($gateway);
        self::assertStringContainsString('globalNotificationSettingsStore', $services);
        self::assertStringContainsString('GlobalNotificationSettingsStore', $gateway);
    }
}
