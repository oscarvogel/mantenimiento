<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppPilotScenarioContractTest extends TestCase
{
    public function testSuperadminCanPrepareSafePilotScenario(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertIsString($controller);
        self::assertIsString($routes);
        self::assertIsString($page);

        self::assertStringContainsString('prepareWhatsAppPilotScenario', $controller);
        self::assertStringContainsString("'whatsapp_pilot_enabled'", $controller);
        self::assertStringContainsString("'es_demo', 1", $controller);
        self::assertStringContainsString("'WA-PILOT-CHOFER'", $controller);
        self::assertStringContainsString("'PRUEBA WHATSAPP PILOTO'", $controller);
        self::assertStringContainsString("'PRUEBA_WHATSAPP'", $controller);
        self::assertStringContainsString('whatsapp/preparar-piloto', $routes);
        self::assertStringContainsString('Preparar prueba piloto', $page);
    }
}
