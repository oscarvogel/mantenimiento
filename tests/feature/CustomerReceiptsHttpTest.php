<?php

declare(strict_types=1);

use App\Application\CustomerAccounts\AccreditReceiptInstrument;
use App\Application\CustomerAccounts\AnnullCustomerReceipt;
use App\Application\CustomerAccounts\ConfirmCustomerReceipt;
use App\Application\CustomerAccounts\CreateCustomerReceipt;
use App\Application\CustomerAccounts\DownloadReceiptAttachment;
use App\Application\CustomerAccounts\GenerateCustomerReceiptPdf;
use App\Application\CustomerAccounts\GetCustomerReceipt;
use App\Application\CustomerAccounts\GetCustomerReceiptForm;
use App\Application\CustomerAccounts\ImputeCustomerPayment;
use App\Application\CustomerAccounts\ListCustomerReceipts;
use App\Application\CustomerAccounts\UploadReceiptAttachment;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class CustomerReceiptsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServicesAreWired(): void
    {
        self::assertInstanceOf(CreateCustomerReceipt::class, service('createCustomerReceipt'));
        self::assertInstanceOf(ConfirmCustomerReceipt::class, service('confirmCustomerReceipt'));
        self::assertInstanceOf(AnnullCustomerReceipt::class, service('annulCustomerReceipt'));
        self::assertInstanceOf(AccreditReceiptInstrument::class, service('accreditReceiptInstrument'));
        self::assertInstanceOf(ImputeCustomerPayment::class, service('imputeCustomerPayment'));
        self::assertInstanceOf(ListCustomerReceipts::class, service('listCustomerReceipts'));
        self::assertInstanceOf(GetCustomerReceiptForm::class, service('getCustomerReceiptForm'));
        self::assertInstanceOf(GetCustomerReceipt::class, service('getCustomerReceipt'));
        self::assertInstanceOf(GenerateCustomerReceiptPdf::class, service('generateCustomerReceiptPdf'));
        self::assertInstanceOf(UploadReceiptAttachment::class, service('uploadReceiptAttachment'));
        self::assertInstanceOf(DownloadReceiptAttachment::class, service('downloadReceiptAttachment'));
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $this->get('clientes/recibos')->assertRedirect();
        $this->get('clientes/recibos/1')->assertRedirect();
        $this->get('clientes/recibos/1/imprimir')->assertRedirect();
    }

    public function testEveryRouteRequiresItsPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $expected = [
            "get('clientes/recibos'" => 'clientes.recibos.ver',
            "get('clientes/recibos/nuevo/(:num)'" => 'clientes.recibos.registrar',
            "post('clientes/recibos'" => 'clientes.recibos.registrar',
            "get('clientes/recibos/(:num)'" => 'clientes.recibos.ver',
            "post('clientes/recibos/(:num)/confirmar'" => 'clientes.recibos.confirmar',
            "post('clientes/recibos/(:num)/anular'" => 'clientes.recibos.anular',
            "post('clientes/recibos/(:num)/descartar'" => 'clientes.recibos.registrar',
            "post('clientes/recibos/(:num)/medios/(:num)/acreditar'" => 'clientes.recibos.confirmar',
            "post('clientes/recibos/(:num)/imputar'" => 'clientes.recibos.imputar',
            "get('clientes/recibos/(:num)/imprimir'" => 'clientes.recibos.imprimir',
            "post('clientes/recibos/(:num)/adjuntos'" => 'clientes.recibos.registrar',
            "get('clientes/recibos/(:num)/adjuntos/(:num)'" => 'clientes.recibos.ver',
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
