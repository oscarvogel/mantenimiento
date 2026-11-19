<?php

declare(strict_types=1);

use App\Application\Trips\CompleteFuelPrices;
use App\Application\Trips\ListPendingFuelPrices;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class OperationalTripFuelPricesHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServicesAreWired(): void
    {
        self::assertInstanceOf(ListPendingFuelPrices::class, service('listPendingFuelPrices'));
        self::assertInstanceOf(CompleteFuelPrices::class, service('completeFuelPrices'));
    }

    public function testAnonymousCannotOpenOrSavePendingFuelPrices(): void
    {
        $this->get('viajes/operativos/combustible-sin-precio')->assertRedirect();
    }

    public function testSavingWithoutCsrfIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('viajes/operativos/combustible-sin-precio', ['importe' => [1 => '1000']]);
    }
}
