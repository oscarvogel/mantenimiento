<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppPreventiveMaintenanceReminderContractTest extends TestCase
{
    public function testPreventiveEventsAreRoutedToDriverWhatsApp(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        self::assertIsString($queue);
        self::assertStringContainsString("'preventivo.proximo', 'preventivo.vencido'", $queue);
        self::assertStringContainsString("entityType() === 'plan_mantenimiento'", $queue);
        self::assertStringContainsString('schedulePreventiveDriverEvent', $queue);
        self::assertStringContainsString('preventivo_chofer:empresa:', $queue);
        self::assertStringContainsString('PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL', $queue);
    }

    public function testSuperadminHasPilotEndpointForARealPreventivePlan(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        self::assertIsString($controller);
        self::assertIsString($routes);
        self::assertStringContainsString('testPreventiveMaintenanceWhatsApp', $controller);
        self::assertStringContainsString('schedulePreventivePilotTest', $controller);
        self::assertStringContainsString('whatsapp/probar-mantenimiento-preventivo', $routes);
        self::assertStringContainsString('No se contactó al chofer real.', $controller);
    }
}
