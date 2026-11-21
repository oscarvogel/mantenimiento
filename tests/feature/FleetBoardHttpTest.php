<?php

declare(strict_types=1);

use App\Application\Gerencial\GetTableroFlota;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class FleetBoardHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testServiceIsWired(): void
    {
        self::assertInstanceOf(GetTableroFlota::class, service('tableroFlota'));
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $this->get('gerencial/flota')->assertRedirect();
        $this->get('gerencial/flota?vista=combustible&dias=7')->assertRedirect();
    }

    public function testTheRouteRequiresItsPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression(
            "#\\\$routes->get\\('gerencial/flota', 'FleetBoard::index', \\['filter' => \\['auth', 'permission:gerencial\\.flota\\.ver'\\]\\]\\)#",
            $routes,
        );
    }
}
