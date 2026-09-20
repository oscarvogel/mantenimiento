<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppCompanyInstanceFallbackContractTest extends TestCase
{
    public function testAutomaticQueueFallsBackToGlobalSettingsInstance(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');

        self::assertIsString($queue);
        self::assertStringContainsString('$globalSettings = $this->settings->get();', $queue);
        self::assertStringContainsString('$globalSettings[\'whatsapp_instance_id\']', $queue);
        self::assertStringNotContainsString('env(\'whatsapp.instanceId\'', $queue);
        self::assertStringContainsString('$rows = $this->db->table(\'notificacion_whatsapp_entregas\')', $queue);
        self::assertStringContainsString('$row[\'instance_id\'] = $instancesByCompany[$companyId] ?? $globalInstanceId;', $queue);
    }
}
