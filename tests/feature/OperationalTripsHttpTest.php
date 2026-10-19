<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class OperationalTripsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

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
