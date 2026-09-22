<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EquipmentTypeTrackingManagementContractTest extends TestCase
{
    public function testEquipmentTypeTrackingCanBeManagedFromCatalogs(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = file_get_contents(APPPATH . 'Controllers/AssetManagement.php');
        $service = file_get_contents(APPPATH . 'Application/Assets/AssetCatalogService.php');
        $catalog = file_get_contents(APPPATH . 'Infrastructure/Assets/CodeIgniterEquipmentTypeCatalog.php');
        $payload = file_get_contents(APPPATH . 'Presentation/OperationsPayload.php');
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/operations/EquipmentCatalogsMasterPage.vue');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertIsString($catalog);
        self::assertIsString($payload);
        self::assertIsString($page);

        self::assertStringContainsString("catalogos/tipos/(:num)", $routes);
        self::assertStringContainsString('updateEquipmentType', $controller);
        self::assertStringContainsString('updateTypeTracking', $service);
        self::assertStringContainsString("'controla_km' =>", $catalog);
        self::assertStringContainsString("'controla_horas' =>", $catalog);
        self::assertStringContainsString("'controlsKm' =>", $payload);
        self::assertStringContainsString("'controlsHours' =>", $payload);
        self::assertStringContainsString('Controla kilómetros', $page);
        self::assertStringContainsString('Controla horas', $page);
    }
}
