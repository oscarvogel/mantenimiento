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
}
