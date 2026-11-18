<?php

declare(strict_types=1);

use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripExpense;
use App\Domain\Trips\TripFuelLoad;
use App\Domain\Trips\TripMoney;
use PHPUnit\Framework\TestCase;

final class TripCostsTest extends TestCase
{
    public function testMoneyConvertsForeignCurrencyToArs(): void
    {
        $money = new TripMoney(100.0, 'USD', 1_200.0);

        self::assertSame(120_000.0, $money->amountArs());
        self::assertSame('USD', $money->currency());
    }

    public function testFuelRequiresPositiveLiters(): void
    {
        $this->expectException(DomainException::class);

        new TripFuelLoad(new DateTimeImmutable(), 0, new TripMoney(100));
    }

    public function testFuelCanWaitForItsPrice(): void
    {
        $load = new TripFuelLoad(new DateTimeImmutable('2026-11-15 09:00:00'), 300.0, null);

        self::assertNull($load->money());
        self::assertFalse($load->hasPrice());
        self::assertTrue((new TripFuelLoad(new DateTimeImmutable('2026-11-15 09:00:00'), 300.0, new TripMoney(435_000.0)))->hasPrice());
    }

    public function testExpenseRequiresPositiveAmount(): void
    {
        $this->expectException(DomainException::class);

        new TripExpense(new DateTimeImmutable(), new TripMoney(0));
    }

    public function testExpenseInfersCashPayerFromLegacyFlag(): void
    {
        $expense = new TripExpense(new DateTimeImmutable(), new TripMoney(100), paidFromCash: true);

        self::assertSame('CAJA', $expense->paidBy());
        self::assertTrue($expense->paidFromCash());
    }

    public function testExpenseRejectsUnknownPayer(): void
    {
        $this->expectException(DomainException::class);

        new TripExpense(new DateTimeImmutable(), new TripMoney(100), paidBy: 'TALLER');
    }

    public function testExpenseKeepsCatalogConceptQuantityAndUnitAmount(): void
    {
        $expense = new TripExpense(
            new DateTimeImmutable(),
            new TripMoney(75_000),
            conceptId: 9,
            quantity: 1.5,
            unitAmount: 50_000.0,
        );

        self::assertSame(9, $expense->conceptId());
        self::assertSame(1.5, $expense->quantity());
        self::assertSame(50_000.0, $expense->unitAmount());
    }

    public function testExpenseRejectsNonPositiveQuantity(): void
    {
        $this->expectException(DomainException::class);

        new TripExpense(new DateTimeImmutable(), new TripMoney(100), conceptId: 9, quantity: 0.0);
    }

    public function testExpenseRejectsInvalidConcept(): void
    {
        $this->expectException(DomainException::class);

        new TripExpense(new DateTimeImmutable(), new TripMoney(100), conceptId: -1);
    }

    public function testCashMovementOnlyAcceptsKnownTypes(): void
    {
        $this->expectException(DomainException::class);

        new TripCashMovement('OTRO', new DateTimeImmutable(), new TripMoney(100));
    }

    public function testCashDeliveryAndReturnAreDistinguished(): void
    {
        $advance = new TripCashMovement(TripCashMovement::ANTICIPO, new DateTimeImmutable(), new TripMoney(100));
        $return = new TripCashMovement(TripCashMovement::DEVOLUCION, new DateTimeImmutable(), new TripMoney(50));

        self::assertTrue($advance->isDelivery());
        self::assertFalse($return->isDelivery());
    }
}
