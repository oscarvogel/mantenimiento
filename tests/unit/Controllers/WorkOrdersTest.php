<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\WorkOrders;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WorkOrdersTest extends TestCase
{
    public function testNormalizesOptionalIntegerQueryFilters(): void
    {
        $controller = new WorkOrders();
        $method = new ReflectionMethod($controller, 'nullableInt');
        $method->setAccessible(true);

        self::assertNull($method->invoke($controller, null));
        self::assertNull($method->invoke($controller, ''));
        self::assertSame(12, $method->invoke($controller, '12'));
    }
}
