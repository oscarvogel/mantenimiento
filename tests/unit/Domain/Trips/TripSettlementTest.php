<?php

declare(strict_types=1);

use App\Domain\Trips\TripSettlement;
use PHPUnit\Framework\TestCase;

final class TripSettlementTest extends TestCase
{
    public function testTotalsCommissionEarningsAndDriverAccount(): void
    {
        $settlement = new TripSettlement(112_000.0, 25_000.0, 50_000.0, 20_000.0, 130_000.0, 5_000.0);

        self::assertSame(-55_000.0, $settlement->accountBalance());
        self::assertSame(82_000.0, $settlement->total());
    }

    public function testExpensesCoveredByCashReduceTheAdvanceDebt(): void
    {
        $settlement = new TripSettlement(100_000.0, 0.0, 0.0, 80_000.0, 100_000.0, 0.0);

        self::assertSame(-20_000.0, $settlement->accountBalance());
        self::assertSame(80_000.0, $settlement->total());
    }

    public function testDriverPaidExpensesAreReimbursed(): void
    {
        $settlement = new TripSettlement(0.0, 0.0, 25_000.0, 0.0, 0.0, 0.0);

        self::assertSame(25_000.0, $settlement->accountBalance());
        self::assertSame(25_000.0, $settlement->total());
    }
}
