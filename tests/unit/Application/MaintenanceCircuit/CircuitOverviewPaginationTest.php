<?php

declare(strict_types=1);

use App\Application\MaintenanceCircuit\CircuitOverviewPagination;
use PHPUnit\Framework\TestCase;

final class CircuitOverviewPaginationTest extends TestCase
{
    public function testNormalizesEveryIndependentPagerWithStrictWhitelist(): void
    {
        $pagination = new CircuitOverviewPagination(
            ['equipments' => 2, 'plans' => -4, 'orders' => 'invalid'],
            ['equipments' => 5, 'plans' => 25, 'notices' => 999, 'orders' => 10],
        );

        self::assertSame(2, $pagination->page('equipments'));
        self::assertSame(1, $pagination->page('plans'));
        self::assertSame(1, $pagination->page('orders'));
        self::assertSame(5, $pagination->pageSize('equipments'));
        self::assertSame(25, $pagination->pageSize('plans'));
        self::assertSame(10, $pagination->pageSize('notices'));
        self::assertSame(10, $pagination->pageSize('readings'));
    }
}
