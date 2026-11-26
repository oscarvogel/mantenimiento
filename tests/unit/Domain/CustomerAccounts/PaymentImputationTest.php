<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerAccounts;

use App\Domain\CustomerAccounts\Currency;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\PaymentImputation;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * La diferencia de cambio de una imputación (#588).
 *
 * Son reglas puras: no dependen de la base de datos ni del framework.
 */final class PaymentImputationTest extends TestCase
{
    private function rate(string $currency, float $value): FrozenExchangeRate
    {
        return new FrozenExchangeRate($currency, $value, new \DateTimeImmutable('2026-11-10'), 'MANUAL');
    }

    public function testLaDiferenciaDeCambioEsElValorDelCobroMenosElValorHistorico(): void
    {
        // El ejemplo del issue: USD 1.000 a 1.600 sobre un documento a 1.500.
        $imputation = new PaymentImputation(
            chargeId: 30,
            currency: Currency::fromCode('USD'),
            amountMinor: 100_000,
            historicalArsMinor: 150_000_000,
            chargeRate: $this->rate('USD', 1_500),
            paymentRate: $this->rate('USD', 1_600),
        );

        self::assertSame(160_000_000, $imputation->paymentArsMinor());
        self::assertSame(150_000_000, $imputation->historicalArsMinor());

        $difference = $imputation->difference();
        self::assertSame(10_000_000, $difference->amountMinor());
        self::assertTrue($difference->isFavorable());
        self::assertSame(10_000_000, $difference->debitMinor());
        self::assertSame(0, $difference->creditMinor());
    }

    public function testUnaCotizacionMenorDaUnaDiferenciaDesfavorable(): void
    {
        $imputation = new PaymentImputation(
            chargeId: 30,
            currency: Currency::fromCode('USD'),
            amountMinor: 100_000,
            historicalArsMinor: 160_000_000,
            chargeRate: $this->rate('USD', 1_600),
            paymentRate: $this->rate('USD', 1_500),
        );

        $difference = $imputation->difference();
        self::assertTrue($difference->isUnfavorable());
        self::assertSame(0, $difference->debitMinor());
        self::assertSame(10_000_000, $difference->creditMinor());
    }

    public function testNoPermiteImputarAMonedaDistinta(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('misma moneda');
        new PaymentImputation(
            chargeId: 30,
            currency: Currency::fromCode('USD'),
            amountMinor: 100_000,
            historicalArsMinor: 150_000_000,
            chargeRate: $this->rate('USD', 1_500),
            paymentRate: $this->rate('ARS', 1),
        );
    }

    public function testRechazaUnImporteImputadoNoPositivo(): void
    {
        $this->expectException(DomainException::class);
        new PaymentImputation(
            chargeId: 30,
            currency: Currency::fromCode('USD'),
            amountMinor: 0,
            historicalArsMinor: 0,
            chargeRate: $this->rate('USD', 1_500),
            paymentRate: $this->rate('USD', 1_600),
        );
    }
}
