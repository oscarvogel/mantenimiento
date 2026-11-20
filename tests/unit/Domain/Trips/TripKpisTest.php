<?php

declare(strict_types=1);

use App\Domain\Trips\TripKpis;
use PHPUnit\Framework\TestCase;

final class TripKpisTest extends TestCase
{
    public function testComputesTripIndicatorsFromRealData(): void
    {
        $kpis = TripKpis::calculate(4_500, 1_800.0, 3_600_000.0, 9_000_000.0, 6_300_000.0, 2_700_000.0, 60.0, 10);

        self::assertSame(4_500, $kpis['km']);
        self::assertSame(10, $kpis['days']);
        self::assertSame(40.0, $kpis['liters_per_100_km']);
        self::assertSame(2.5, $kpis['km_per_liter']);
        self::assertSame(450.0, $kpis['km_per_day']);
        self::assertSame(2_000.0, $kpis['invoice_per_km']);
        self::assertSame(1_400.0, $kpis['cost_per_km']);
        self::assertSame(800.0, $kpis['fuel_cost_per_km']);
        self::assertSame(600.0, $kpis['margin_per_km']);
        self::assertSame(150_000.0, $kpis['invoice_per_ton']);
    }

    public function testNeverInventsAnIndicatorWithoutKilometersOrLiters(): void
    {
        $kpis = TripKpis::calculate(null, 0.0, 0.0, null, null, null, null, null);

        foreach ($kpis as $name => $value) {
            self::assertNull($value, $name . ' debe quedar sin dato');
        }
    }

    public function testKeepsLitersButNotPerKilometerIndicatorsWhileReturnOdometerIsMissing(): void
    {
        $kpis = TripKpis::calculate(null, 500.0, 900_000.0, 2_000_000.0, null, null, 0.0, 3);

        self::assertSame(500.0, $kpis['liters']);
        self::assertNull($kpis['liters_per_100_km']);
        self::assertNull($kpis['km_per_liter']);
        self::assertNull($kpis['fuel_cost_per_km']);
        self::assertNull($kpis['tons'], 'Cero toneladas no es un dato cargado');
        self::assertNull($kpis['invoice_per_ton']);
    }

    public function testCountsCalendarDaysIncludingDepartureDay(): void
    {
        self::assertSame(19, TripKpis::days('2026-10-30', '2026-11-17 18:00:00', '2026-11-20'));
        self::assertSame(1, TripKpis::days('2026-11-20 08:00:00', '2026-11-20 20:00:00', '2026-11-20'));
    }

    public function testCountsUntilTodayWhileTheTripIsStillInProgress(): void
    {
        self::assertSame(22, TripKpis::days('2026-10-30', null, '2026-11-20'));
    }

    public function testReturnsNoDaysWithoutDepartureOrWithReversedDates(): void
    {
        self::assertNull(TripKpis::days(null, null, '2026-11-20'));
        self::assertNull(TripKpis::days('2026-11-20', '2026-11-01', '2026-11-20'));
    }
}
