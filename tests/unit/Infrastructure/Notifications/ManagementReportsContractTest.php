<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ManagementReportsContractTest extends TestCase
{
    public function testSchedulerIsWiredIntoNotificationCycle(): void
    {
        $services = file_get_contents(APPPATH . 'Config/Services.php');
        $cycle = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationCycle.php');
        $scheduler = file_get_contents(APPPATH . 'Application/Notifications/ScheduleManagementReports.php');

        self::assertIsString($services);
        self::assertIsString($cycle);
        self::assertIsString($scheduler);
        self::assertStringContainsString('managementReports', $services);
        self::assertStringContainsString('ScheduleManagementReports', $cycle);
        self::assertStringContainsString('informe.gerencial.diario', $scheduler);
        self::assertStringContainsString('informe.gerencial.semanal', $scheduler);
        self::assertStringContainsString("hash('sha256', \$recipient)", $scheduler);
    }

    public function testManagementReportsAreConfiguredAndTestedFromSuperadmin(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($page);
        self::assertStringContainsString('empresas/(:num)/informes/prueba', $routes);
        self::assertStringContainsString('testCompanyManagementReport', $controller);
        self::assertStringContainsString('Informes para dueño / gerencia', $page);
        self::assertStringContainsString('informe_diario_habilitado', $page);
        self::assertStringContainsString('informe_semanal_habilitado', $page);
        self::assertStringContainsString('Probar informe diario', $page);
        self::assertStringContainsString('Probar informe semanal', $page);
    }

    public function testManagementReportsStaySeparateFromOperationalCompanyEmail(): void
    {
        $dispatch = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationDispatch.php');
        $gateway = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterEmailNotificationGateway.php');

        self::assertIsString($dispatch);
        self::assertIsString($gateway);
        self::assertStringContainsString("str_starts_with(\$eventType, 'informe.gerencial.')", $dispatch);
        self::assertStringContainsString("str_starts_with((string) (\$first['tipo_evento'] ?? ''), 'informe.gerencial.')", $gateway);
        self::assertStringContainsString('<html lang="es-AR">', $gateway);
        self::assertStringContainsString("setHeader('Content-Language', 'es-AR')", $gateway);
        self::assertStringContainsString('setAltMessage', $gateway);
        self::assertStringContainsString('managementReportText', $gateway);
        self::assertStringContainsString('Vogel Consultoría', $gateway);
        self::assertStringContainsString('vogelconsultoria.com.ar', $gateway);
        self::assertStringContainsString('Abrir sistema de mantenimiento', $gateway);
        self::assertStringContainsString('managementMetricTone', $gateway);
        self::assertStringContainsString('Control de lecturas', $gateway);
        self::assertStringContainsString('!LECTURA|', file_get_contents(APPPATH . 'Application/Notifications/ScheduleManagementReports.php'));
        self::assertStringContainsString('staleReadingDetails', file_get_contents(APPPATH . 'Application/Notifications/ScheduleManagementReports.php'));
    }

    public function testHalfHourCronUsesMinuteKeyAndBoundedBatch(): void
    {
        $cycle = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationCycle.php');
        $controller = file_get_contents(APPPATH . 'Controllers/NotificationCron.php');
        $docs = file_get_contents(ROOTPATH . 'docs/notificaciones-cron-ferozo.md');

        self::assertIsString($cycle);
        self::assertIsString($controller);
        self::assertIsString($docs);
        self::assertStringContainsString("format('Y-m-d-H-i')", $cycle);
        self::assertStringContainsString('dispatchLimit', $cycle);
        self::assertStringContainsString("alerts.webCronBatchLimit", $controller);
        self::assertStringContainsString('alerts.webCronBatchLimit = 25', $docs);
        self::assertStringContainsString('cada 30 minutos', $docs);
    }

    public function testCompanyOverviewRemainsUsableBeforeMigration(): void
    {
        $administration = file_get_contents(APPPATH . 'Infrastructure/Organization/CodeIgniterOrganizationAdministration.php');

        self::assertIsString($administration);
        self::assertStringContainsString("fieldExists('informe_diario_habilitado', 'empresas')", $administration);
    }
}
