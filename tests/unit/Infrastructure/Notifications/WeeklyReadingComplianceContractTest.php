<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WeeklyReadingComplianceContractTest extends TestCase
{
    public function testWeeklyComplianceFlowIsImplementedAndIdempotent(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertIsString($queue);
        self::assertIsString($controller);
        self::assertIsString($page);
        self::assertStringContainsString("return 'wednesday';", $queue);
        self::assertStringContainsString("return 'friday';", $queue);
        self::assertStringContainsString("'seguimiento_lectura_miercoles'", $queue);
        self::assertStringContainsString("'seguimiento_lectura_viernes'", $queue);
        self::assertStringContainsString('hasKilometerReadingSince', $queue);
        self::assertStringContainsString("where('fecha_lectura >=',", $queue);
        self::assertStringContainsString("'Segundo recordatorio de kilometraje'", $queue);
        self::assertStringContainsString("'Aviso final de kilometraje'", $queue);
        self::assertStringContainsString("'Segundo lembrete de quilometragem'", $queue);
        self::assertStringContainsString("'Aviso final de quilometragem'", $queue);
        self::assertStringContainsString("'Responsable de mantenimiento'", $queue);
        self::assertStringContainsString("'Kilometraje semanal pendiente'", $queue);
        self::assertStringContainsString("'equipo.lectura_semanal_incumplida'", $queue);
        self::assertStringContainsString("'Regularizado: se registró kilometraje después de programar el seguimiento semanal.'", $queue);
        self::assertStringContainsString("'Omitido por blindaje anti-duplicado:", $queue);
        self::assertStringContainsString('$seenWeekly', $queue);
        self::assertStringContainsString('$dedupeKey', $queue);
        self::assertStringContainsString('$baseDeliveryKey', $queue);
        self::assertStringContainsString("'test|' : 'prod|'", $queue);
        self::assertStringContainsString("?string $forcedStage = null", $queue);
        self::assertStringContainsString("['initial', 'wednesday', 'friday']", $queue);
        self::assertStringContainsString("getPost('etapa')", $controller);
        self::assertStringContainsString("scheduleWeeklyReadingReminders(true, $testKey, $batchLimit, $stage)", $controller);
        self::assertStringContainsString('Simulador semanal de kilometraje', $page);
        self::assertStringContainsString('value="wednesday"', $page);
        self::assertStringContainsString('value="friday"', $page);
    }
}
