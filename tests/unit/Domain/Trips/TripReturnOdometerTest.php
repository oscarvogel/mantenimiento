<?php

declare(strict_types=1);

use App\Domain\Trips\TripConsumption;
use App\Domain\Trips\TripReturnOdometer;
use PHPUnit\Framework\TestCase;

final class TripReturnOdometerTest extends TestCase
{
    public function testPrefersTelemetryOverTheLastEquipmentReading(): void
    {
        $suggestion = TripReturnOdometer::suggest(100_000, 104_000, ['km' => 103_200, 'observed_at' => '2026-11-17 21:00:00', 'provider' => 'AUTOTRAC']);

        self::assertSame(103_200, $suggestion['km']);
        self::assertSame(TripReturnOdometer::TELEMETRIA, $suggestion['source']);
        self::assertSame('2026-11-17 21:00:00', $suggestion['observed_at']);
    }

    public function testIgnoresTelemetryThatIsNotAfterDeparture(): void
    {
        $suggestion = TripReturnOdometer::suggest(100_000, 104_000, ['km' => 99_000, 'observed_at' => '2026-10-01 10:00:00', 'provider' => 'AUTOTRAC']);

        self::assertSame(104_000, $suggestion['km']);
        self::assertSame(TripReturnOdometer::EQUIPO, $suggestion['source']);
    }

    public function testSuggestsNothingWhenNoReadingIsAfterDeparture(): void
    {
        self::assertNull(TripReturnOdometer::suggest(100_000, 100_000, null));
        self::assertNull(TripReturnOdometer::suggest(100_000, null, null));
    }

    public function testFlagsConsumptionOutsideTheTypicalRange(): void
    {
        self::assertTrue(TripConsumption::isUnusual(88.0));
        self::assertTrue(TripConsumption::isUnusual(12.0));
        self::assertFalse(TripConsumption::isUnusual(38.5));
        self::assertFalse(TripConsumption::isUnusual(null));
    }
}
