<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrato del informe de lecturas atrasadas para la gerencia.
 *
 * Comprueba que las piezas queden conectadas entre si: migracion, caso de uso,
 * ciclo de notificaciones, cola de WhatsApp, pantalla de Superadmin y el
 * enlace de prueba manual. Si una de estas piezas se desconecta, el aviso deja
 * de salir y nadie se entera hasta que un cliente avisa.
 */
final class ManagementReadingReportContractTest extends TestCase
{
    private function migrationPath(): string
    {
        return ROOTPATH . 'app/Database/Migrations/2026-10-23-140000_AddManagementReadingReportSettings.php';
    }

    public function testMigrationAddsTheManagementContactAndTheReportSettings(): void
    {
        self::assertFileExists($this->migrationPath());
        $migration = (string) file_get_contents($this->migrationPath());

        self::assertStringContainsString('telefono_gerencia', $migration);
        self::assertStringContainsString('informe_lecturas_whatsapp_habilitado', $migration);
        self::assertStringContainsString('informe_lecturas_hora', $migration);
        self::assertStringContainsString('informe_lecturas_dias', $migration);
        self::assertStringContainsString('public function down(): void', $migration, 'La migracion debe ser reversible.');
    }

    public function testTheReportIsWiredIntoTheNotificationCycle(): void
    {
        $services = (string) file_get_contents(APPPATH . 'Config/Services.php');
        $cycle = (string) file_get_contents(APPPATH . 'Application/Notifications/RunNotificationCycle.php');

        self::assertStringContainsString('managementReadingReport', $services);
        self::assertStringContainsString('ScheduleManagementReadingReport', $services);
        self::assertStringContainsString('ScheduleManagementReadingReport', $cycle);
        self::assertStringContainsString('management_reading_report', $cycle);
    }

    public function testTheQueueStoresTheMessageAndTheIdempotencyKey(): void
    {
        $queue = (string) file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');

        self::assertStringContainsString('enqueueManagementReadingReport', $queue);
        self::assertStringContainsString("'clave_entrega' => \$deliveryKey", $queue);
        self::assertStringContainsString("'mensaje' => \$message", $queue);
        self::assertStringContainsString("'tipo_evento' => ScheduleManagementReadingReport::EVENT_TYPE", $queue);
    }

    public function testTheReportCanBeTestedManuallyFromSuperadmin(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $page = (string) file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertStringContainsString('empresas/(:num)/informes/lecturas/prueba', $routes);
        self::assertStringContainsString('testCompanyReadingReport', $controller);
        self::assertStringContainsString('telefono_gerencia', $controller);
        self::assertStringContainsString('informe_lecturas_whatsapp_habilitado', $page);
        self::assertStringContainsString('telefono_gerencia', $page);
        self::assertStringContainsString('Probar aviso de lecturas', $page);
    }

    public function testThePhoneIsValidatedAsAWhatsAppNumberBeforeSaving(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');

        self::assertStringContainsString('assertValidManagementPhone', $controller);
        self::assertStringContainsString('WhatsAppPhone::normalize', $controller);
    }

    public function testBothChannelsShareTheSameThreshold(): void
    {
        $emailReport = (string) file_get_contents(APPPATH . 'Application/Notifications/ScheduleManagementReports.php');
        $settings = (string) file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterManagementReadingReportSettingsReadModel.php');

        self::assertStringContainsString('informe_lecturas_dias', $emailReport, 'El informe por email usa el umbral de la empresa.');
        self::assertStringContainsString('informe_lecturas_dias', $settings, 'El aviso por WhatsApp usa el mismo umbral de la empresa.');
    }

    public function testBothChannelsExcludeEquipmentThatDoesNotControlKilometres(): void
    {
        $emailReport = (string) file_get_contents(APPPATH . 'Application/Notifications/ScheduleManagementReports.php');
        $whatsappReport = (string) file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterReadingComplianceReadModel.php');

        self::assertStringContainsString('te.controla_km = 1', $emailReport);
        self::assertStringContainsString("->where('te.controla_km', 1)", $whatsappReport);
    }
}
