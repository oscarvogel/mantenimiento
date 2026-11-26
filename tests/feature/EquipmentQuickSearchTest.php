<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class EquipmentQuickSearchTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testAnonymousIsSentToLogin(): void
    {
        $this->get('mantenimiento/equipos/buscar?q=AB')->assertRedirect();
    }

    public function testTheRouteRequiresEquipmentReadPermissionAndPrecedesTheDetailRoute(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $search = "\$routes->get('equipos/buscar', 'AssetManagement::quickSearch', ['filter' => 'permission:equipos.ver']);";

        self::assertStringContainsString($search, $routes);
        self::assertLessThan(
            strpos($routes, "\$routes->get('equipos/(:num)', 'EquipmentManagement::show/\$1'"),
            strpos($routes, $search),
        );
    }
}
