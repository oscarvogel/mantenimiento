<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppDeliveryReconciliationContractTest extends TestCase
{
    public function testInternationalPhoneAndGatewayReconciliationAreExplicit(): void
    {
        $gateway = file_get_contents(APPPATH . 'Infrastructure/Notifications/VogelWhatsAppApiGateway.php');
        $gatewayPort = file_get_contents(APPPATH . 'Application/Notifications/Port/WhatsAppNotificationGateway.php');
        $phone = file_get_contents(APPPATH . 'Domain/Notifications/WhatsAppPhone.php');
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $queuePort = file_get_contents(APPPATH . 'Application/Notifications/Port/WhatsAppNotificationDeliveryQueue.php');
        $dispatch = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationDispatch.php');
        $notifier = file_get_contents(APPPATH . 'Application/Notifications/NotifyAdminsMissingDriverPhones.php');
        $auditReadModel = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterDriverPhoneAuditReadModel.php');
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-23-111500_AddWhatsAppProviderMessageId.php');
        $userDigestMigration = file_get_contents(APPPATH . 'Database/Migrations/2026-10-07-192500_AddUserToWhatsAppNotificationDeliveries.php');
        $userDigestSchedule = file_get_contents(APPPATH . 'Application/Notifications/UserWhatsAppDigestSchedule.php');

        self::assertIsString($gateway);
        self::assertIsString($gatewayPort);
        self::assertIsString($phone);
        self::assertIsString($queue);
        self::assertIsString($queuePort);
        self::assertIsString($dispatch);
        self::assertIsString($notifier);
        self::assertIsString($auditReadModel);
        self::assertIsString($migration);
        self::assertIsString($userDigestMigration);
        self::assertIsString($userDigestSchedule);

        self::assertStringContainsString('WhatsAppPhone::normalize', $gateway);
        self::assertStringNotContainsString("strlen(\$digits) === 10", $phone);
        self::assertStringNotContainsString("'549' . \$digits", $phone);
        self::assertStringContainsString("str_starts_with(\$raw, '54')", $phone);
        self::assertStringContainsString("str_starts_with(\$raw, '55')", $phone);
        self::assertStringContainsString("str_starts_with(\$raw, '56')", $phone);
        self::assertStringContainsString("preg_match('/^549[0-9]{10}$/', \$raw)", $phone);
        self::assertStringContainsString("preg_match('/^55[0-9]{10,11}$/', \$raw)", $phone);
        self::assertStringContainsString("preg_match('/^56[0-9]{9}$/', \$raw)", $phone);

        self::assertStringContainsString('getMessageStatus', $gatewayPort);
        self::assertStringContainsString('/messages/', $gateway);
        self::assertStringContainsString('providerMessageId', $gateway);

        self::assertStringContainsString('PENDIENTE_CONFIRMACION', $queue);
        self::assertStringContainsString('awaitingConfirmation', $queuePort);
        self::assertStringContainsString('reconcileStatus', $queuePort);
        self::assertStringContainsString('whatsapp_gateway_failed', $dispatch);
        self::assertStringContainsString('getMessageStatus(', $dispatch);
        self::assertStringContainsString('provider_message_id', $migration);
        self::assertStringContainsString("'usuario_id'", $userDigestMigration);
        self::assertStringContainsString('scheduleUserDailyDigests', $queuePort);
        self::assertStringContainsString('scheduleUserDailyDigests', $dispatch);
        self::assertStringContainsString("'usuario.resumen_diario'", $queue);
        self::assertStringContainsString("userWhatsAppDigestTime', '08:00'", $queue);
        self::assertStringContainsString("format('N') >= 6", $userDigestSchedule);

        self::assertStringContainsString("where('r.nombre', 'Responsable de mantenimiento')", $auditReadModel);
        self::assertStringContainsString('formato internacional', $notifier);
        self::assertStringContainsString('whatsapp.entrega_fallida', $queue);
        self::assertStringContainsString('Falló una notificación WhatsApp', $queue);
    }
}
