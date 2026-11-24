<?php

declare(strict_types=1);

use App\Application\Trips\GetPanelCombustible;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Módulo de Combustible: la pantalla que muestra la tabla de cargas del viaje.
 *
 * Es de solo lectura: no hay POST que pueda escribir combustible desde acá.
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
}
