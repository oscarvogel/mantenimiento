<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationWebCronContractTest extends TestCase
{
    public function testCliAndHttpReuseTheSameNotificationCycle(): void
    {
        $cycle = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationCycle.php');
        $command = file_get_contents(APPPATH . 'Commands/DispatchNotifications.php');
        $controller = file_get_contents(APPPATH . 'Controllers/NotificationCron.php');

        self::assertIsString($cycle);
        self::assertIsString($command);
        self::assertIsString($controller);
        self::assertStringContainsString('DetectOverduePlansAutomatically', $cycle);
        self::assertStringContainsString('CollectOperationalNotifications', $cycle);
        self::assertStringContainsString('RunNotificationDispatch', $cycle);
        self::assertStringContainsString('new RunNotificationCycle(', $command);
        self::assertStringContainsString('new RunNotificationCycle(', $controller);
    }

    public function testWebCronRequiresExplicitEnablementAndASecretWithMinimumLength(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/NotificationCron.php');
        $env = file_get_contents(ROOTPATH . '.env.example');

        self::assertIsString($controller);
        self::assertIsString($env);
        self::assertStringContainsString("env('alerts.webCronEnabled', false)", $controller);
        self::assertStringContainsString("env('alerts.webCronToken', '')", $controller);
        self::assertStringContainsString('strlen($expected) < 32', $controller);
        self::assertStringContainsString('hash_equals($expected, $token)', $controller);
        self::assertStringContainsString('alerts.webCronEnabled', $env);
        self::assertStringContainsString('alerts.webCronToken', $env);
    }

    public function testCronRouteIsPublicOnlyAtTransportLayerAndManualRouteKeepsSuperadminFilter(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("\$routes->get('cron/notificaciones/(:segment)', 'NotificationCron::dispatch/\$1');", $routes);
        self::assertStringContainsString("\$routes->group('superadmin', ['filter' => 'superadmin']", $routes);
        self::assertStringContainsString("\$routes->post('notificaciones/despachar', 'NotificationCron::manual');", $routes);
    }

    public function testSuperadminExposesTheManualDispatchActionWithCsrfProtectedForm(): void
    {
        $payload = file_get_contents(APPPATH . 'Presentation/AdministrationPayload.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminPage.vue');

        self::assertIsString($payload);
        self::assertIsString($page);
        self::assertStringContainsString("'dispatchNotifications' => base_url('superadmin/notificaciones/despachar')", $payload);
        self::assertStringContainsString(':action="data.actions.dispatchNotifications"', $page);
        self::assertStringContainsString('<CsrfField :csrf="data.csrf" />', $page);
        self::assertStringContainsString('Procesar notificaciones ahora', $page);
    }
}
