<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\DemoAdmin;
use App\Database\Seeds\DemoCompanySeeder;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

final class DemoAdminTest extends CIUnitTestCase
{
    public function testBuildsDemoSeederWithRuntimeDatabaseDependencies(): void
    {
        $controller = new DemoAdmin();
        $factory = new ReflectionMethod(DemoAdmin::class, 'createDemoSeeder');
        $factory->setAccessible(true);

        $seeder = $factory->invoke($controller);

        $this->assertInstanceOf(DemoCompanySeeder::class, $seeder);
    }
}
