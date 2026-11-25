<?php

declare(strict_types=1);

use App\Application\Neumaticos\GetNeumaticDetail;
use App\Application\Neumaticos\GetNeumaticRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Padrón y ficha de neumáticos por HTTP: ver exige `neumaticos.ver`; dar de
 * alta, corregir y cambiar el estado exigen `neumaticos.operar` y CSRF. El
 * scope por empresa y sucursal lo prueban los tests del caso de uso.
 */
final class NeumaticsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServicesAreWired(): void
    {
        self::assertInstanceOf(GetNeumaticRegistry::class, service('neumaticRegistry'));
        self::assertInstanceOf(GetNeumaticDetail::class, service('neumaticDetail'));
    }

    public function testAnonymousCannotOpenTheRegistryNorADetail(): void
    {
        $this->get('neumaticos')->assertRedirect();
        $this->get('neumaticos/7')->assertRedirect();
    }

    public function testRegisteringWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('neumaticos', ['numero' => 'TSA 1', 'sucursal_id' => '1']);
    }

    public function testUpdatingWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('neumaticos/7', ['medida' => '295/80']);
    }

    public function testChangingStatusWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('neumaticos/7/estado', ['estado' => 'MEDIO_USO', 'motivo' => 'x']);
    }

    public function testEveryRouteDeclaresItsPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression("#group\\('neumaticos', \\['filter' => \\['auth'\\]\\]#", $routes);
        foreach (["''", "'\\(:num\\)'"] as $path) {
            self::assertMatchesRegularExpression("#->get\\({$path}, 'Neumatics::[a-z]+(/\\\$1)?', \\['filter' => 'permission:neumaticos\\.ver'\\]#", $routes);
        }
        foreach (["''", "'\\(:num\\)'", "'\\(:num\\)/estado'"] as $path) {
            self::assertMatchesRegularExpression("#->post\\({$path}, 'Neumatics::[a-z]+(/\\\$1)?', \\['filter' => 'permission:neumaticos\\.operar'\\]#", $routes);
        }
    }
}
