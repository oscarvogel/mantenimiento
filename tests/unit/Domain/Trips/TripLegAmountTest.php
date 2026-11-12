<?php

declare(strict_types=1);

use App\Domain\Trips\TripLegAmount;
use PHPUnit\Framework\TestCase;

final class TripLegAmountTest extends TestCase
{
    public function testKeepsTheAmountWithItsCurrencyAndRate(): void
    {
        $amount = new TripLegAmount(4_700, 'USD', 1_250.0);

        self::assertSame(4_700.0, $amount->amount());
        self::assertSame('USD', $amount->currency());
        self::assertSame(1_250.0, $amount->exchangeRate());
        self::assertSame(5_875_000.0, $amount->amountArs());
    }

    public function testAnAmountInPesosIsNotConverted(): void
    {
        $amount = new TripLegAmount(5_875_000, 'ARS', 1.0);

        self::assertSame(5_875_000.0, $amount->amountArs());
        self::assertSame(1.0, $amount->exchangeRate());
    }

    public function testTheRateOfPesosIsAlwaysOne(): void
    {
        $amount = new TripLegAmount(1_000, 'ARS', 1_250.0);

        self::assertSame(1.0, $amount->exchangeRate());
        self::assertSame(1_000.0, $amount->amountArs());
    }

    public function testAcceptsAnEmptyAmount(): void
    {
        $amount = new TripLegAmount(null);

        self::assertNull($amount->amount());
        self::assertNull($amount->amountArs());
        self::assertSame('ARS', $amount->currency());
    }

    public function testRejectsANegativeAmount(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no puede ser negativo');

        new TripLegAmount(-1);
    }

    public function testRejectsACurrencyThatIsNotThreeLetters(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tres letras');

        new TripLegAmount(100, 'PESOS');
    }

    public function testRejectsAnExchangeRateOfZero(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mayor que cero');

        new TripLegAmount(100, 'USD', 0.0);
    }

    public function testNormalizesTheCurrencyCode(): void
    {
        $amount = new TripLegAmount(100, 'usd', 1_250.0);

        self::assertSame('USD', $amount->currency());
    }
}
