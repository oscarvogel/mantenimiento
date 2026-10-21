<?php

declare(strict_types=1);

use App\Application\Trips\AddTripLeg;
use App\Application\Trips\FinishTrip;
use App\Application\Trips\RegisterTripExpense;
use App\Application\Trips\RegisterTripFuel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class OperationalTripsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testTripServicesAreWired(): void
    {
        self::assertInstanceOf(AddTripLeg::class, service('addTripLeg'));
        self::assertInstanceOf(FinishTrip::class, service('finishTrip'));
        self::assertInstanceOf(RegisterTripFuel::class, service('registerTripFuel'));
        self::assertInstanceOf(RegisterTripExpense::class, service('registerTripExpense'));
    }

    public function testAnonymousCannotOpenOperationalTripsList(): void
    {
        $this->get('viajes/operativos')->assertRedirect();
    }

    public function testAnonymousCannotOpenOperationalTripDetail(): void
    {
        $this->get('viajes/operativos/42')->assertRedirect();
    }

    public function testAnonymousCannotOpenCreateForm(): void
    {
        $this->get('viajes/operativos/nuevo')->assertRedirect();
    }
}
