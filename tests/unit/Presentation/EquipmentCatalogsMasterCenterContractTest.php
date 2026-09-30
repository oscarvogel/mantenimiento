<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #353: contrato del centro unificado de Maestros.
 *
 * Protege tres cosas que se alteraron al adaptar el PR #354:
 *  - las rutas del centro existen de verdad en Routes.php de main;
 *  - el tracking KM/H de tipos de equipo sigue disponible en el modal;
 *  - Sucursales NO se duplica dentro de Maestros.
 */
final class EquipmentCatalogsMasterCenterContractTest extends TestCase
{
    public function testCenterLinksPointToRoutesThatActuallyExist(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $payload = (string) file_get_contents(APPPATH . 'Presentation/OperationsPayload.php');

        foreach ([
            'expirationTypes' => 'maestros/vencimientos',
            'services' => 'servicios',
            'preventiveLibrary' => 'importaciones/biblioteca',
            'providers' => 'proveedores',
        ] as $key => $route) {
            self::assertStringContainsString("'{$key}'", $payload, "El centro debe exponer el acceso {$key}.");
            self::assertStringContainsString("'{$route}'", $routes, "La ruta {$route} debe existir en Routes.php.");
        }
    }

    public function testBranchesAreNotDuplicatedInsideTheMastersCenter(): void
    {
        $payload = (string) file_get_contents(APPPATH . 'Presentation/OperationsPayload.php');
        $page = (string) file_get_contents(ROOTPATH . 'frontend/src/pages/operations/EquipmentCatalogsMasterPage.vue');
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertStringNotContainsString("'branches'", $payload, 'Sucursales no se expone desde el centro de Maestros.');
        self::assertStringNotContainsString('routes.branches', $page, 'La pagina de Maestros no debe enlazar a Sucursales.');
        self::assertStringNotContainsString('Sucursales', $page, 'La pagina de Maestros no debe ofrecer el acceso Sucursales.');

        // Sigue siendo alcanzable por su via propia, en Administracion.
        self::assertStringContainsString("group('administracion'", $routes);
        self::assertStringContainsString("'sucursales'", $routes);
    }

    public function testEquipmentTypeKeepsKilometerAndHourTrackingAvailable(): void
    {
        $page = (string) file_get_contents(ROOTPATH . 'frontend/src/pages/operations/EquipmentCatalogsMasterPage.vue');
        $port = (string) file_get_contents(APPPATH . 'Application/Assets/Port/EquipmentTypeCatalog.php');

        self::assertStringContainsString('name="controla_km"', $page, 'El modal de tipo debe seguir exponiendo el control de km.');
        self::assertStringContainsString('name="controla_horas"', $page, 'El modal de tipo debe seguir exponiendo el control de horas.');
        self::assertStringContainsString('item.updateUrl', $page, 'El modal debe postear a la ruta de actualizacion del tipo.');
        self::assertStringContainsString('updateTracking', $port, 'updateTracking no puede desaparecer del puerto.');
    }
}
