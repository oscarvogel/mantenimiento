<?php

declare(strict_types=1);

namespace Tests\Unit\Application\CustomerAccounts;

use App\Application\CustomerAccounts\RegisterCustomerPaymentCommand;
use App\Domain\CustomerAccounts\Money;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * El importe de un cobro viaja como texto decimal exacto.
 *
 * El dominio calcula en centavos enteros: si el importe pasara por un float,
 * un cobro con cuatro decimales llegaría al libro con centavos inventados o
 * perdidos. El camino feliz por HTTP se validó de punta a punta en `fasa_189`;
 * estas pruebas dejan la frontera del comando vigilada.
 */
final class CustomerPaymentAmountContractTest extends TestCase
{
    public function testElComandoEsperaElImporteComoTexto(): void
    {
        $parameters = (new ReflectionClass(RegisterCustomerPaymentCommand::class))->getConstructor()->getParameters();
        $amount = null;
        foreach ($parameters as $parameter) {
            if ($parameter->getName() === 'amount') {
                $amount = $parameter;
            }
        }

        self::assertNotNull($amount, 'El comando del cobro tiene que declarar el importe.');
        self::assertSame('string', (string) $amount->getType());
    }

    public function testElControladorArmaElCobroConElImporteEnTexto(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/CustomerAccounts.php');

        self::assertStringContainsString('private function requiredAmountText(string $key): string', $controller);
        self::assertStringContainsString("\$this->requiredAmountText('importe')", $controller);
    }

    public function testElImporteEnTextoConservaLosDecimales(): void
    {
        // El libro lleva centavos: la cuarta decimal se redondea al centavo
        // (half-up) y no se arrastra ruido binario.
        self::assertSame(100012, Money::toMinor('1000.1234'));
        self::assertSame(100013, Money::toMinor('1000.125'));
        self::assertSame(100000, Money::toMinor('1000'));
        self::assertSame(100050, Money::toMinor('1000,50'));
    }
}
