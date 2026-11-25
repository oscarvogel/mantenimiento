<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerAccounts;

use App\Domain\CustomerAccounts\Money;
use DomainException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testParsesDecimalStringsWithoutBinaryFloatDrift(): void
    {
        self::assertSame(30, Money::toMinor('0.10') + Money::toMinor('0.20'));
        self::assertSame(5_000_000_00, Money::toMinor('5.000.000,00'));
        self::assertSame(123_45, Money::toMinor('123,45'));
        self::assertSame(10_001, Money::toMinor('100.005'));
        self::assertSame(1, Money::toMinor('0.005'));
        self::assertSame(0, Money::toMinor('0.004'));
        self::assertSame('1234.57', Money::format(123_457));
        self::assertSame(1234.57, Money::toDecimal(123_457));
    }

    public function testRejectsInvalidAmounts(): void
    {
        $this->expectException(DomainException::class);
        Money::toMinor('no es un importe');
    }

    public function testMultipliesByHistoricalRateWithHalfUpRounding(): void
    {
        self::assertSame(75_000_000, Money::multiplyByRate(Money::toMinor('500'), '1500'));
        self::assertSame(3_086_402, Money::multiplyByRate(Money::toMinor('123.45'), '250.012345'));
        self::assertSame(101, Money::multiplyByRate(100, '1.005'));
    }

    public function testRejectsNonPositiveRates(): void
    {
        $this->expectException(DomainException::class);
        Money::multiplyByRate(100, '0');
    }
}
