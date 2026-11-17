<?php

declare(strict_types=1);

use App\Application\CustomerAccounts\AnnulCustomerCharge;
use App\Application\CustomerAccounts\FormalizeLegCharge;
use App\Application\CustomerAccounts\GetCommercialClosingQueue;
use App\Application\CustomerAccounts\GetCustomerStatement;
use App\Application\CustomerAccounts\ListCustomerAccounts;
use App\Application\CustomerAccounts\MarkLegNonCollectible;
use App\Application\CustomerAccounts\RegisterOpeningBalance;
use App\Application\CustomerAccounts\ReopenNonCollectibleLeg;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class CustomerAccountsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServicesAreWired(): void
    {
        self::assertInstanceOf(FormalizeLegCharge::class, service('formalizeLegCharge'));
        self::assertInstanceOf(AnnulCustomerCharge::class, service('annulCustomerCharge'));
        self::assertInstanceOf(MarkLegNonCollectible::class, service('markLegNonCollectible'));
        self::assertInstanceOf(ReopenNonCollectibleLeg::class, service('reopenNonCollectibleLeg'));
        self::assertInstanceOf(RegisterOpeningBalance::class, service('registerOpeningBalance'));
        self::assertInstanceOf(GetCommercialClosingQueue::class, service('getCommercialClosingQueue'));
        self::assertInstanceOf(ListCustomerAccounts::class, service('listCustomerAccounts'));
        self::assertInstanceOf(GetCustomerStatement::class, service('getCustomerStatement'));
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $this->get('viajes/cierre-comercial')->assertRedirect();
        $this->get('clientes/cuenta-corriente')->assertRedirect();
        $this->get('clientes/cuenta-corriente/4')->assertRedirect();
    }

    public function testEveryRouteRequiresItsPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $expected = [
            "get('viajes/cierre-comercial'" => 'clientes.cc.ver',
            "get('clientes/cuenta-corriente'" => 'clientes.cc.ver',
            "get('clientes/cuenta-corriente/(:num)'" => 'clientes.cc.ver',
            "post('viajes/cierre-comercial/tramos/(:num)/formalizar'" => 'clientes.cc.formalizar',
            "post('viajes/cierre-comercial/tramos/(:num)/no-cobrable'" => 'clientes.cc.formalizar',
            "post('viajes/cierre-comercial/tramos/(:num)/reabrir'" => 'clientes.cc.formalizar',
            "post('clientes/cuenta-corriente/cargos/(:num)/anular'" => 'clientes.cc.formalizar',
            "post('clientes/cuenta-corriente/(:num)/saldo-inicial'" => 'clientes.cc.formalizar',
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
