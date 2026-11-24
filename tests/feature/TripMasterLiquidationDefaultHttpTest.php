<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Porcentaje de liquidacion por defecto de la empresa.
 *
 * Es el respaldo para los choferes que todavia no tienen fila en el maestro
 * de porcentajes: sin el, sus viajes quedan sin comision.
 */
final class TripMasterLiquidationDefaultHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testTheCompanyDefaultCannotBeSavedWithoutACsrfToken(): void
    {
        // El endpoint es una mutacion: sin token CSRF no se guarda nada.
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);

        $this->post('viajes/maestros/liquidaciones/default', ['porcentaje_defecto' => '14']);
    }

    public function testAnonymousCannotOpenTheTripMasters(): void
    {
        $this->get('viajes/maestros')->assertRedirect();
    }

    public function testTheRouteRequiresTheMastersEditPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression(
            "#post\('liquidaciones/default', 'TripMasters::liquidationDefault', \['filter' => 'permission:viajes\.maestros\.editar'\]\)#",
            $routes,
        );
    }
}
