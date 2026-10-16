<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SuperAdminCompanyPresentationProfileTest extends TestCase
{
    public function testAssignmentIsPostOnlyInsideTheSuperadminRouteGroupAndCallsDedicatedUseCase(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        self::assertIsString($routes);
        self::assertIsString($controller);

        $groupStart = strpos($routes, '$routes->group(\'superadmin\', [\'filter\' => \'superadmin\']');
        $groupEnd = strpos($routes, "});", $groupStart ?: 0);
        self::assertNotFalse($groupStart);
        self::assertNotFalse($groupEnd);
        $adminRoutes = substr($routes, $groupStart, $groupEnd - $groupStart);
        self::assertStringContainsString('$routes->post(\'empresas/(:num)/presentacion\', \'SuperAdmin::assignCompanyPresentationProfile/$1\')', $adminRoutes);
        self::assertStringNotContainsString('$routes->get(\'empresas/(:num)/presentacion\'', $routes);
        self::assertStringContainsString("service('assignCompanyPresentationProfile')", $controller);
        self::assertStringContainsString('getPost(\'frontend_profile\')', $controller);
        self::assertStringContainsString('\'frontend_profile\' => \'required|max_length[64]\'', $controller);
        self::assertStringContainsString('$handler->execute($this->actor(), $companyId,', $controller);
    }
}
