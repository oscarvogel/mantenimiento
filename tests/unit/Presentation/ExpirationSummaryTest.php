<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\ExpirationSummary;
use PHPUnit\Framework\TestCase;

final class ExpirationSummaryTest extends TestCase
{
    public function testCountsOverdueAndUpcomingWindows(): void
    {
        $items = array_map(static fn (int $days): array => ['daysUntil' => $days], [-3, -1, 0, 5, 7, 8, 30, 31]);

        self::assertSame(['total' => 8, 'overdue' => 2, 'next7' => 3, 'next30' => 5], ExpirationSummary::of($items));
    }

    public function testEmptyListCountsZero(): void
    {
        self::assertSame(['total' => 0, 'overdue' => 0, 'next7' => 0, 'next30' => 0], ExpirationSummary::of([]));
    }
}
