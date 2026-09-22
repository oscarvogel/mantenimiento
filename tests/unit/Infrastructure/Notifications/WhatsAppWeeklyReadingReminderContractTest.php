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
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $env = file_get_contents(ROOTPATH . '.env.example');

        self::assertIsString($queue);
        self::assertIsString($dispatch);
        self::assertIsString($port);
        self::assertIsString($controller);
        self::assertIsString($routes);
        self::assertIsString($env);

        self::assertStringContainsString('scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null): int', $port);
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
        self::assertStringContainsString('Vogel Consultoría · Mantenimiento', $queue);
        self::assertStringContainsString('https://vogelconsultoria.com.ar/mantenimiento', $queue);
        self::assertStringContainsString('weeklyReadingReminderIsDue', $queue);
        self::assertStringContainsString("env('alerts.weeklyReadingReminderDay', 1)", $queue);
        self::assertStringContainsString("env('alerts.weeklyReadingReminderTime', '08:00')", $queue);
        self::assertStringContainsString(':prueba:', $queue);
        self::assertStringContainsString('testWeeklyReadingReminderWhatsApp', $controller);
        self::assertStringContainsString('whatsapp/probar-recordatorio-km', $routes);
        self::assertStringContainsString('alerts.weeklyReadingReminderDay', $env);
        self::assertStringContainsString('alerts.weeklyReadingReminderTime', $env);
    }
}
