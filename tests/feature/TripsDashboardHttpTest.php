<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class TripsDashboardHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testAnonymousCannotOpenTripsDashboard(): void
    {
        $this->get('viajes')->assertRedirect();
    }
}
