<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class TripProfitabilityHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testAnonymousCannotOpenProfitability(): void
    {
        $this->get('viajes/rentabilidad')->assertRedirect();
    }
}
