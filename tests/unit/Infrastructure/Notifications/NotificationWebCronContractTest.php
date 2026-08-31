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
        $services = file_get_contents(APPPATH . 'Config/Services.php');

        self::assertIsString($cycle);
        self::assertIsString($command);
        self::assertIsString($controller);
        self::assertIsString($services);
        self::assertStringContainsString('DetectOverduePlansAutomatically', $cycle);
        self::assertStringContainsString('CollectOperationalNotifications', $cycle);
        self::assertStringContainsString('RunNotificationDispatch', $cycle);
        self::assertStringContainsString("service('notificationCycle')", $command);
        self::assertStringContainsString("service('notificationCycle')", $controller);
        self::assertStringContainsString('public static function notificationCycle(', $services);
        self::assertStringNotContainsString('new RunNotificationCycle(', $command);
        self::assertStringNotContainsString('new RunNotificationCycle(', $controller);
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
        self::assertStringContainsString('hash_equals($expected, $provided)', $controller);
        self::assertStringContainsString("getHeaderLine('X-Cron-Token')", $controller);
        self::assertStringContainsString("getHeaderLine('Authorization')", $controller);
        self::assertStringContainsString('alerts.webCronEnabled', $env);
        self::assertStringContainsString('alerts.webCronToken', $env);
        self::assertStringContainsString('alerts.webCronRateLimit', $env);
        self::assertStringContainsString('alerts.webCronRateWindowSeconds', $env);
    }

    public function testCronRouteIsPublicOnlyAtTransportLayerAndManualRouteKeepsSuperadminFilter(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("\$routes->post('internal/cron/notifications/dispatch', 'NotificationCron::dispatch');", $routes);
        self::assertStringContainsString("\$routes->get('internal/cron/notifications/dispatch', 'NotificationCron::methodNotAllowed');", $routes);
        self::assertStringContainsString("\$routes->get('cron/notificaciones/(:segment)', 'NotificationCron::legacyDispatch/\$1');", $routes);
        self::assertStringContainsString("\$routes->group('superadmin', ['filter' => 'superadmin']", $routes);
        self::assertStringContainsString("\$routes->post('notificaciones/despachar', 'NotificationCron::manual');", $routes);
    }

    public function testNewCronRouteIsExcludedFromCsrfBecauseItUsesHeaderAuthentication(): void
    {
        $filters = file_get_contents(APPPATH . 'Config/Filters.php');

        self::assertIsString($filters);
        self::assertStringContainsString("'internal/cron/notifications/dispatch'", $filters);
    }

    public function testNewCronContractDefinesMethodAndAuthenticationFailures(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/NotificationCron.php');

        self::assertIsString($controller);
        self::assertStringContainsString('setStatusCode(405)', $controller);
        self::assertStringContainsString("setHeader('Allow', 'POST')", $controller);
        self::assertStringContainsString('setStatusCode(401)', $controller);
        self::assertStringContainsString('setStatusCode(403)', $controller);
        self::assertStringContainsString("'forbidden'", $controller);
        self::assertStringContainsString('setStatusCode(429)', $controller);
        self::assertStringContainsString('setStatusCode(409)', $controller);
        self::assertStringContainsString('notification_dispatch_failed', $controller);
        self::assertStringContainsString('legacy', strtolower($controller));
    }

    public function testCoolifyEntrypointReadsDottedRuntimeVariablesFromProcessEnvironment(): void
    {
        $entrypoint = file_get_contents(ROOTPATH . 'docker/entrypoint.php');

        self::assertIsString($entrypoint);
        self::assertStringContainsString("file_get_contents('/proc/self/environ')", $entrypoint);
        self::assertStringContainsString('getenv($key)', $entrypoint);
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
