<?php

declare(strict_types=1);

use App\Domain\Trips\TripLeg;
use App\Domain\Trips\TripLegAmount;
use PHPUnit\Framework\TestCase;

final class TripLegTest extends TestCase
{
    public function testAllowsMissingAmountsAndDoesNotInventCommission(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(null),
            new TripLegAmount(null),
        );

        self::assertNull($leg->invoice()->amount());
        self::assertNull($leg->liquidation()->amount());
        self::assertNull($leg->invoice()->amountArs());
        self::assertNull($leg->liquidation()->amountArs());
        self::assertNull($leg->commission(14.0));
    }

    public function testCalculatesCommissionWhenLiquidationAmountIsPresent(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(null),
            new TripLegAmount(800_000),
        );

        self::assertSame(112_000.0, $leg->commission(14.0));
    }

    public function testCalculatesCommissionOnTheLiquidationAmountConvertedToPesos(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(4_700, 'USD', 1_250.0),
            new TripLegAmount(5_875_000, 'ARS'),
        );

        self::assertSame(822_500.0, $leg->commission(14.0));
    }

    public function testRejectsNegativeAmounts(): void
    {
        $this->expectException(DomainException::class);

        new TripLegAmount(-1);
    }

    public function testKeepsEachFreightWithItsOwnCurrency(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(4_700, 'USD', 1_250.0),
            new TripLegAmount(5_875_000, 'ARS'),
        );

        self::assertSame('USD', $leg->invoice()->currency());
        self::assertSame(1_250.0, $leg->invoice()->exchangeRate());
        self::assertSame(5_875_000.0, $leg->invoice()->amountArs());

        self::assertSame('ARS', $leg->liquidation()->currency());
        self::assertSame(1.0, $leg->liquidation()->exchangeRate());
        self::assertSame(5_875_000.0, $leg->liquidation()->amountArs());
    }

    public function testKeepsTheDatesOfTheLegWithoutTime(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(null),
            new TripLegAmount(null),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-11-02 06:30:00'),
            new DateTimeImmutable('2026-11-04 18:00:00'),
        );

        self::assertSame('2026-11-02', $leg->startDate()?->format('Y-m-d'));
        self::assertSame('2026-11-04', $leg->endDate()?->format('Y-m-d'));
    }

    public function testAcceptsALegWithoutDates(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(null),
            new TripLegAmount(null),
        );

        self::assertNull($leg->startDate());
        self::assertNull($leg->endDate());
    }

    public function testRejectsAnEndDateBeforeTheStartDate(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fecha de fin');

        new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(null),
            new TripLegAmount(null),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-11-04'),
            new DateTimeImmutable('2026-11-02'),
        );
    }
}
