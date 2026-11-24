<?php

declare(strict_types=1);

use App\Application\Trips\FuelPanelFilters;
use App\Application\Trips\GetPanelCombustible;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Módulo de Combustible: la pantalla del combustible cargado por viaje.
 *
 * Es de lectura con `viajes.ver`; cargar una carga o completar un importe
 * exige `viajes.operar`, como en el resto de los movimientos del viaje.
 */
final class FuelModuleHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServiceIsWired(): void
    {
        self::assertInstanceOf(GetPanelCombustible::class, service('panelCombustible'));
    }

    public function testAnonymousCannotOpenTheFuelModule(): void
    {
        $this->get('viajes/combustible')->assertRedirect();
    }

    public function testSavingWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('viajes/combustible/21/carga', ['litros' => 100]);
    }

    public function testCompletingPricesWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('viajes/combustible/precios', ['importe' => [1 => '1000']]);
    }

    public function testWritingRoutesRequireTheirOwnPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression(
            "#'viajes/combustible/\\(:num\\)/carga',[^\\n]*'auth', 'permission:viajes\\.operar'#",
            $routes,
        );
        self::assertMatchesRegularExpression(
            "#'viajes/combustible/precios',[^\\n]*'auth', 'permission:viajes\\.operar'#",
            $routes,
        );
    }

    public function testTheFiltersAreReadFromTheQuery(): void
    {
        $filtros = FuelPanelFilters::fromQuery(['desde' => '2026-11-01', 'hasta' => '2026-11-24', 'camion' => '7']);

        self::assertSame('2026-11-01', $filtros->from);
        self::assertSame('2026-11-24', $filtros->to);
        self::assertSame(7, $filtros->equipmentId);
    }
}
