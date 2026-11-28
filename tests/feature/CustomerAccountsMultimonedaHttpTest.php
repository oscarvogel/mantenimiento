<?php

declare(strict_types=1);

use App\Application\CustomerAccounts\AnnulCustomerPayment;
use App\Application\CustomerAccounts\CreditCustomerPayment;
use App\Application\CustomerAccounts\RegisterCustomerPayment;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Rutas y permisos de la capa de cobranzas multimoneda (#588).
 */
final class CustomerAccountsMultimonedaHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServicesAreWired(): void
    {
        self::assertInstanceOf(RegisterCustomerPayment::class, service('registerCustomerPayment'));
        self::assertInstanceOf(CreditCustomerPayment::class, service('creditCustomerPayment'));
        self::assertInstanceOf(AnnulCustomerPayment::class, service('annulCustomerPayment'));
    }

    public function testRegisteringACobroWithoutCsrfIsRejected(): void
    {
        // El filtro de CSRF corre antes que el de autenticación: sin token la
        // petición moría antes de llegar a la autorización.
        $this->expectException(SecurityException::class);
        $this->post('clientes/cuenta-corriente/4/cobranzas', ['importe' => '100']);
    }

    public function testCreditingACobroWithoutCsrfIsRejected(): void
    {
        $this->expectException(SecurityException::class);
        $this->post('clientes/cuenta-corriente/cobranzas/5/acreditar');
    }

    public function testAnnullingACobroWithoutCsrfIsRejected(): void
    {
        $this->expectException(SecurityException::class);
        $this->post('clientes/cuenta-corriente/cobranzas/5/anular', ['motivo' => 'Error de carga']);
    }

    public function testEveryRouteRequiresItsPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $expected = [
            "post('clientes/cuenta-corriente/(:num)/cobranzas'" => 'clientes.cc.cobrar',
            "post('clientes/cuenta-corriente/cobranzas/(:num)/acreditar'" => 'clientes.cc.cobrar',
            "post('clientes/cuenta-corriente/cobranzas/(:num)/anular'" => 'clientes.cc.cobrar',
        ];
        foreach ($expected as $route => $permission) {
            self::assertMatchesRegularExpression(
                '#\$routes->' . preg_quote($route, '#') . ",[^\\n]*'auth', 'permission:" . preg_quote($permission, '#') . "'#",
                $routes,
                $route,
            );
        }
    }
}
