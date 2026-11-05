<?php

declare(strict_types=1);

use App\Application\Trips\GetOperationalTripsDashboard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class OperationalTripsDashboardHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testDashboardServiceIsWired(): void
    {
        self::assertInstanceOf(GetOperationalTripsDashboard::class, service('getOperationalTripsDashboard'));
    }

    public function testAnonymousCannotOpenOperationalTripsDashboard(): void
    {
        $this->get('viajes/operativos/dashboard')->assertRedirect();
    }
}
