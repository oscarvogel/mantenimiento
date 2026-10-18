<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class TripImportsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testAnonymousCannotOpenTripImports(): void
    {
        $this->get('superadmin/viajes/importar')->assertRedirect();
        $this->get('superadmin/viajes/importaciones/1')->assertRedirect();
    }
}
