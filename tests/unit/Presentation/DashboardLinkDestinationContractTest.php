<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * #339: contrato de los destinos del dashboard y de la proteccion 403.
 *
 * El reporte original ("un Administrador ve un CTA y recibe 403") no era un
 * problema de permisos. Un CTA se habilita con un permiso y a veces llevaba a
 * una ruta que exigia OTRO, o directamente a una ruta inexistente. Estos tests
 * fijan que cada destino declarado existe y que su permiso coincide con el que
 * habilita el enlace.
 */
final class DashboardLinkDestinationContractTest extends CIUnitTestCase
{
    private function payload(): string
    {
        return (string) file_get_contents(APPPATH . 'Presentation/DashboardPayload.php');
    }

    private function routes(): string
    {
        return (string) file_get_contents(APPPATH . 'Config/Routes.php');
    }

    public function testDashboardLinksNeverTargetTheBareMaintenanceRoot(): void
    {
        $payload = $this->payload();

        // /mantenimiento es hoy Chatbot::index (permission:chatbot.usar) y ademas
        // colisiona con el directorio fisico del webroot. Ningun CTA puede apuntar ahi.
        self::assertStringNotContainsString(
            "base_url('mantenimiento')",
            $payload,
            'Los enlaces del dashboard no deben apuntar a la raiz /mantenimiento.',
        );
        self::assertStringNotContainsString(
            "base_url('mantenimiento?",
            $payload,
            'Los enlaces del dashboard no deben usar query strings sobre la raiz /mantenimiento.',
        );
    }

    public function testMaintenanceAndOrdersLinksUseRoutesThatActuallyExist(): void
    {
        $payload = $this->payload();
        $routes  = $this->routes();

        self::assertStringContainsString("base_url('mantenimiento/planes')", $payload);
        self::assertStringContainsString("'planes', 'PreventivePlans::index'", $routes);
        self::assertStringContainsString("permission:planes.ver", $routes);

        self::assertStringContainsString("base_url('mantenimiento/ordenes')", $payload);
        self::assertStringContainsString("'ordenes', 'WorkOrders::index'", $routes);
        self::assertStringContainsString("permission:ordenes.ver", $routes);
    }

    public function testOrdersLinkIsGatedByTheSamePermissionItsRouteRequires(): void
    {
        $payload = $this->payload();

        // El enlace "Ver ordenes" se habilitaba con ordenes.editar mientras la ruta
        // exige ordenes.ver. Un usuario con solo editar veia el enlace y recibia 403.
        self::assertStringContainsString(
            "\$canViewOrders = \$actor->hasPermission('ordenes.ver');",
            $payload,
            'El CTA de ordenes debe usar el mismo permiso que exige su ruta.',
        );
        self::assertStringContainsString("'orders' => \$canViewOrders ? \$ordersUrl : '#',", $payload);
    }

    public function testMaintenanceLinkStaysGatedByTheSamePermissionItsRouteRequires(): void
    {
        $payload = $this->payload();

        self::assertStringContainsString("\$canPlans = \$actor->hasPermission('planes.ver');", $payload);
        self::assertStringContainsString("'maintenance' => \$canPlans ? \$plansUrl : '#',", $payload);
    }
}
