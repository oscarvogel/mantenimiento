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
