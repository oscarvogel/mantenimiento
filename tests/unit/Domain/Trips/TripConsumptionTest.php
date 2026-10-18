<?php

declare(strict_types=1);

use App\Domain\Trips\TripConsumption;
use PHPUnit\Framework\TestCase;

final class TripConsumptionTest extends TestCase
{
    public function testComputesLitersPerHundredKilometers(): void
    {
        self::assertSame(50.0, TripConsumption::litersPer100Km(50.0, 100));
        self::assertSame(33.3, TripConsumption::litersPer100Km(100.0, 300));
    }

    public function testReturnsNullWithoutMinimumData(): void
    {
        self::assertNull(TripConsumption::litersPer100Km(0.0, 100));
        self::assertNull(TripConsumption::litersPer100Km(50.0, 0));
    }
}
