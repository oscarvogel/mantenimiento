<?php

declare(strict_types=1);

use App\Application\Platform\GetFleetAvailability;
use App\Application\Platform\GetPortalHome;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class PortalFleetHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServicesAreWired(): void
    {
        self::assertInstanceOf(GetFleetAvailability::class, service('fleetAvailability'));
        self::assertInstanceOf(GetPortalHome::class, service('portalHome'));
    }

    public function testAnonymousIsSentToLoginEvenWithABranchFilter(): void
    {
        $this->get('inicio?sucursal=99')->assertRedirect();
        $this->get('inicio?sucursal=abc')->assertRedirect();
    }
}
