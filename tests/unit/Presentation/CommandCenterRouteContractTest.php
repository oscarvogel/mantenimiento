<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

final class CommandCenterRouteContractTest extends CIUnitTestCase
{
    public function testCommandCenterRouteRequiresAnAuthenticatedSession(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertStringContainsString(
            '$routes->get(\'inicio\', \'CommandCenter::index\', [\'filter\' => \'auth\']);',
            $routes,
        );
    }
}
