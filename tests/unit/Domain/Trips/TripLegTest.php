<?php

declare(strict_types=1);

use App\Domain\Trips\TripLeg;
use PHPUnit\Framework\TestCase;

final class TripLegTest extends TestCase
{
    public function testAllowsMissingAmountsAndDoesNotInventCommission(): void
    {
        $leg = new TripLeg('Rosario', 'Córdoba', null, null);

        self::assertNull($leg->invoiceAmount());
        self::assertNull($leg->liquidationAmount());
        self::assertNull($leg->invoiceAmountArs());
        self::assertNull($leg->liquidationAmountArs());
        self::assertNull($leg->commission(14.0));
    }

    public function testCalculatesCommissionWhenLiquidationAmountIsPresent(): void
    {
        $leg = new TripLeg('Rosario', 'Córdoba', null, 800_000);

        self::assertSame(112_000.0, $leg->commission(14.0));
    }

    public function testRejectsNegativeAmounts(): void
    {
        $this->expectException(DomainException::class);

        new TripLeg('Rosario', 'Córdoba', -1, null);
    }

    public function testKeepsTheDatesOfTheLegWithoutTime(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            null,
            null,
            null,
            null,
            'ARS',
            1.0,
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
        $leg = new TripLeg('Rosario', 'Córdoba', null, null);

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
            null,
            null,
            null,
            null,
            'ARS',
            1.0,
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
