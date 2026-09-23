<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppDeliveryReconciliationContractTest extends TestCase
{
    public function testInternationalPhoneAndGatewayReconciliationAreExplicit(): void
    {
        $gateway = file_get_contents(APPPATH . 'Infrastructure/Notifications/VogelWhatsAppApiGateway.php');
        $gatewayPort = file_get_contents(APPPATH . 'Application/Notifications/Port/WhatsAppNotificationGateway.php');
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $queuePort = file_get_contents(APPPATH . 'Application/Notifications/Port/WhatsAppNotificationDeliveryQueue.php');
        $dispatch = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationDispatch.php');
        $notifier = file_get_contents(APPPATH . 'Application/Notifications/NotifyAdminsMissingDriverPhones.php');
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-23-111500_AddWhatsAppProviderMessageId.php');

        self::assertIsString($gateway);
        self::assertIsString($gatewayPort);
        self::assertIsString($queue);
        self::assertIsString($queuePort);
        self::assertIsString($dispatch);
        self::assertIsString($notifier);
        self::assertIsString($migration);

        self::assertStringNotContainsString("strlen(\$digits) === 10", $gateway);
        self::assertStringNotContainsString("'549' . \$digits", $gateway);
        self::assertStringContainsString("str_starts_with(\$raw, '54')", $gateway);
        self::assertStringContainsString("str_starts_with(\$raw, '55')", $gateway);
        self::assertStringContainsString("str_starts_with(\$raw, '56')", $gateway);
        self::assertStringContainsString("preg_match('/^549[0-9]{10}$/', \$raw)", $gateway);
        self::assertStringContainsString("preg_match('/^55[0-9]{10,11}$/', \$raw)", $gateway);
        self::assertStringContainsString("preg_match('/^56[0-9]{9}$/', \$raw)", $gateway);
        self::assertStringContainsString('validación estructural E.164', $gateway);

        self::assertStringContainsString('getMessageStatus', $gatewayPort);
        self::assertStringContainsString('/messages/', $gateway);
        self::assertStringContainsString('providerMessageId', $gateway);

        self::assertStringContainsString('PENDIENTE_CONFIRMACION', $queue);
        self::assertStringContainsString('awaitingConfirmation', $queuePort);
        self::assertStringContainsString('reconcileStatus', $queuePort);
        self::assertStringContainsString('whatsapp_gateway_failed', $dispatch);
        self::assertStringContainsString('getMessageStatus(', $dispatch);
        self::assertStringContainsString('provider_message_id', $migration);

        self::assertStringContainsString('Responsable de mantenimiento', $notifier);
        self::assertStringContainsString('formato internacional', $notifier);
        self::assertStringContainsString('whatsapp.entrega_fallida', $queue);
        self::assertStringContainsString('Falló una notificación WhatsApp', $queue);
    }
}
