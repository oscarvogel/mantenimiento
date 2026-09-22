<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MissingDriverPhonesAdminNotificationContractTest extends TestCase
{
    public function testMissingPhonesAreReportedOnlyToCompanyAdministratorsInNotificationCenter(): void
    {
        $service = file_get_contents(APPPATH . 'Application/Notifications/NotifyAdminsMissingDriverPhones.php');
        $cycle = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationCycle.php');
        $services = file_get_contents(APPPATH . 'Config/Services.php');
        $dispatch = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationDispatch.php');
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');

        self::assertIsString($service);
        self::assertIsString($cycle);
        self::assertIsString($services);
        self::assertIsString($dispatch);
        self::assertIsString($queue);

        self::assertStringContainsString("where('r.nombre', 'Administrador')", $service);
        self::assertStringContainsString("chofer.telefono_faltante", $service);
        self::assertStringContainsString("Notification::forRecipient", $service);
        self::assertStringNotContainsString("scheduleCompany(", $service);
        self::assertStringContainsString("'/empleados'", $service);
        self::assertStringContainsString('missingDriverPhones?->execute()', $cycle);
        self::assertStringContainsString('notifyAdminsMissingDriverPhones(false)', $services);

        self::assertStringContainsString("env('alerts.whatsappBatchLimit', 5)", $dispatch);
        self::assertStringContainsString("env('alerts.whatsappSendIntervalMs', 2000)", $dispatch);
        self::assertStringContainsString('usleep($intervalMs * 1000)', $dispatch);
        self::assertStringContainsString("tipo_evento = 'equipo.recordatorio_lectura_semanal'", $queue);
    }
}
