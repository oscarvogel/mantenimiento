<?php

declare(strict_types=1);

use App\Application\Neumaticos\GetNeumaticDeposit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Depósito de neumáticos por HTTP: ver exige `neumaticos.ver`; registrar un
 * movimiento exige `neumaticos.operar` y CSRF. Las reglas de existencia las
 * prueban el dominio y el caso de uso.
 */
final class NeumaticDepositsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServiceIsWired(): void
    {
        self::assertInstanceOf(GetNeumaticDeposit::class, service('neumaticDeposit'));
    }

    public function testAnonymousCannotOpenTheDeposit(): void
    {
        $this->get('neumaticos/deposito')->assertRedirect();
    }

    public function testRegisteringAMovementWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('neumaticos/deposito/movimientos', ['tipo' => 'ENTRADA', 'deposito_id' => '4', 'neumatico_id' => '10', 'cantidad' => '1']);
    }

    public function testEveryRouteDeclaresItsPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression("#group\\('neumaticos/deposito', \\['filter' => \\['auth'\\]\\]#", $routes);
        self::assertMatchesRegularExpression("#->get\\('', 'NeumaticDeposits::index', \\['filter' => 'permission:neumaticos\\.ver'\\]#", $routes);
        self::assertMatchesRegularExpression("#->post\\('movimientos', 'NeumaticDeposits::movement', \\['filter' => 'permission:neumaticos\\.operar'\\]#", $routes);
    }
}
