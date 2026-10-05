<?php

declare(strict_types=1);

use App\Application\Notifications\CollectOperationalNotifications;
use Config\Services;
use PHPUnit\Framework\TestCase;

final class NotificationServiceWiringTest extends TestCase
{
    public function testOperationalNotificationCollectorCanBeBuiltFromTheServiceContainer(): void
    {
        $collector = Services::operationalNotificationCollector(false);

        self::assertInstanceOf(CollectOperationalNotifications::class, $collector);
    }

    public function testDemoHourPlansUseDateOffsetsMatchingTheirInterval(): void
    {
        $seeder = file_get_contents(APPPATH . 'Database/Seeds/DemoCompanySeeder.php');

        self::assertIsString($seeder);
        self::assertMatchesRegularExpression("~'VENCIDO_H'.*'-188 days'.*'-8 days'~s", $seeder);
        self::assertMatchesRegularExpression("~'PROXIMO_H'.*'-168 days'.*'\\+12 days'~s", $seeder);
    }

    // La regresion de aislamiento de planes incoherentes (#167) ya no se verifica
    // grepeando el fuente: vive en PreventiveEventSourceIsolationTest, que arma la
    // base real y comprueba que el plan sano sigue emitiendo su evento.

    public function testWhatsAppGatewayUsesGlobalNotificationSettingsStoreInsteadOfDirectEnvCredentials(): void
    {
        $services = file_get_contents(APPPATH . 'Config/Services.php');

        self::assertIsString($services);
        self::assertStringContainsString('$settings = static::globalNotificationSettingsStore(false)->get();', $services);
        self::assertStringContainsString("\$settings['whatsapp_enabled']", $services);
        self::assertStringContainsString("\$settings['whatsapp_api_url']", $services);
        self::assertStringContainsString("\$settings['whatsapp_api_key']", $services);
        self::assertStringContainsString("\$settings['whatsapp_instance_id']", $services);
        self::assertStringNotContainsString("env('whatsapp.apiKey'", $services);
    }
}
