<?php

declare(strict_types=1);

use App\Application\Neumaticos\NeumaticMasterCatalog;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Maestros de Neumáticos por HTTP: ver exige `neumaticos.ver`; crear, editar,
 * activar e inactivar exigen `neumaticos.operar` y CSRF. El scope por empresa
 * y sucursal lo prueban los tests del caso de uso.
 */
final class NeumaticMastersHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServiceIsWired(): void
    {
        self::assertInstanceOf(NeumaticMasterCatalog::class, service('neumaticMasterCatalog'));
    }

    public function testAnonymousCannotOpenTheMasters(): void
    {
        $this->get('neumaticos/maestros')->assertRedirect();
    }

    public function testCreatingWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('neumaticos/maestros', ['catalogo' => 'marcas', 'nombre' => 'Michelin']);
    }

    public function testUpdatingWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('neumaticos/maestros/marcas/3', ['nombre' => 'Michelin']);
    }

    public function testChangingStatusWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('neumaticos/maestros/marcas/3/estado', ['activo' => '0']);
    }

    public function testEveryRouteDeclaresItsPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression("#group\('neumaticos/maestros', \['filter' => \['auth'\]\]#", $routes);
        self::assertMatchesRegularExpression("#->get\('', 'NeumaticMasters::index', \['filter' => 'permission:neumaticos\.ver'\]#", $routes);
        foreach (["''", "'\(:segment\)/\(:num\)'", "'\(:segment\)/\(:num\)/estado'"] as $path) {
            self::assertMatchesRegularExpression("#->post\({$path}, 'NeumaticMasters::[a-z]+(/\\$1/\\$2)?', \['filter' => 'permission:neumaticos\.operar'\]#", $routes);
        }
    }
}
