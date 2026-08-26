<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ListEquipmentReadingPermissionContractTest extends TestCase
{
    public function testQuickReadingsRouteRemainsProtectedByEquipmentViewUntilDedicatedScopeIsIntroduced(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        self::assertIsString($routes);
        self::assertStringContainsString("$routes->get('lecturas/rapidas', 'QuickReadings::index', ['filter' => 'permission:equipos.ver']);", $routes);
    }
}
