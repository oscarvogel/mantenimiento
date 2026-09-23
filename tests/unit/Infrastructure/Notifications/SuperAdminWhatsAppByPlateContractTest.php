<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SuperAdminWhatsAppByPlateContractTest extends TestCase
{
    public function testDirectedPilotTestUsesSingleEquipmentAndValidatesPublicToken(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertIsString($controller);
        self::assertIsString($routes);
        self::assertIsString($queue);
        self::assertIsString($page);

        self::assertStringContainsString('testWeeklyReadingReminderByPlate', $controller);
        self::assertStringContainsString("getPost('patente_equipo')", $controller);
        self::assertStringContainsString("'patente-' . \$equipmentId", $controller);
        self::assertStringContainsString('$equipmentId,', $controller);
        self::assertStringContainsString('la entrega preparada no corresponde al equipo solicitado', $controller);
        self::assertStringContainsString('El token público fue validado contra este mismo equipo', $controller);

        self::assertStringContainsString('whatsapp/probar-por-patente', $routes);
        self::assertStringContainsString('Probar recordatorio por patente/equipo', $page);
        self::assertStringContainsString('name="patente_equipo"', $page);
        self::assertStringContainsString('No ejecuta el cron global ni procesa otros choferes', $page);
        self::assertStringContainsString('aunque el modo piloto global esté apagado', $page);
        self::assertStringNotContainsString('Activá el modo piloto de WhatsApp antes de probar una patente.', $controller);
        self::assertStringContainsString('sin importar el estado del piloto global', $controller);

        self::assertStringContainsString('?int $onlyEquipmentId = null, bool $forcePilotDestination = false', $queue);
        self::assertStringContainsString('$effectivePilot = $pilotEnabled || $forcePilotDestination', $queue);
        self::assertStringContainsString('$equipmentId !== $onlyEquipmentId', $queue);
        self::assertStringContainsString("resolveActiveToken(hash('sha256', \$token))", $queue);
        self::assertStringContainsString('Token público inconsistente', $queue);
    }
}
