<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppWeeklyReadingReminderContractTest extends TestCase
{
    public function testWeeklyReminderUsesCurrentDriverQrAndIsoWeekIdempotency(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $dispatch = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationDispatch.php');
        $port = file_get_contents(APPPATH . 'Application/Notifications/Port/WhatsAppNotificationDeliveryQueue.php');

        self::assertIsString($queue);
        self::assertIsString($dispatch);
        self::assertIsString($port);

        self::assertStringContainsString('scheduleWeeklyReadingReminders', $port);
        self::assertStringContainsString('scheduleWeeklyReadingReminders', $dispatch);
        self::assertStringContainsString("where('a.rol', 'CHOFER')", $queue);
        self::assertStringContainsString("where('a.fecha_hasta', null)", $queue);
        self::assertStringContainsString("where('te.controla_km', 1)", $queue);
        self::assertStringContainsString("where('co.notificaciones_whatsapp_habilitadas', 1)", $queue);
        self::assertStringContainsString("join('equipo_tokens_publicos t'", $queue);
        self::assertStringContainsString('format(\'o-\\\\WW\')', $queue);
        self::assertStringContainsString('recordatorio_lectura_semanal', $queue);
        self::assertStringContainsString('Cargar kilometraje', $queue);
        self::assertStringContainsString('mantenimiento/publico/equipo/', $queue);
        self::assertStringContainsString('NO ENVIADO AL DESTINATARIO REAL', $queue);
    }
}
