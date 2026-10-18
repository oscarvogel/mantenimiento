<?php

declare(strict_types=1);

use App\Domain\Trips\TripDistance;
use PHPUnit\Framework\TestCase;

final class TripDistanceTest extends TestCase
{
    public function testComputesReliableDelta(): void
    {
        $distance = new TripDistance();

        self::assertSame(TripDistance::OK, $distance->status(100, 200));
        self::assertSame(100, $distance->reliable(100, 200));
    }

    public function testMissingReadingsAreNotComputed(): void
    {
        $distance = new TripDistance();

        self::assertSame(TripDistance::MISSING, $distance->status(null, 200));
        self::assertSame(TripDistance::MISSING, $distance->status(100, null));
        self::assertNull($distance->reliable(null, 200));
    }

    public function testReversedOdometerIsRejected(): void
    {
        $distance = new TripDistance();

        self::assertSame(TripDistance::REVERSED, $distance->status(200, 100));
        self::assertSame(TripDistance::REVERSED, $distance->status(100, 100));
        self::assertNull($distance->reliable(200, 100));
    }

    public function testOutlierIsRejected(): void
    {
        $distance = new TripDistance();

        self::assertSame(TripDistance::OUTLIER, $distance->status(0, 20_000));
        self::assertNull($distance->reliable(0, 20_000));
    }

    public function testMaximumIsConfigurable(): void
    {
        $distance = new TripDistance(500);

        self::assertSame(TripDistance::OUTLIER, $distance->status(0, 600));
        self::assertSame(400, $distance->reliable(0, 400));
    }
}
