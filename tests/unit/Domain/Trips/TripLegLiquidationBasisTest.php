<?php

declare(strict_types=1);

use App\Domain\Trips\TripLeg;
use App\Domain\Trips\TripLegAmount;
use App\Domain\Trips\TripLegLiquidationBasis;
use PHPUnit\Framework\TestCase;

/**
 * Las dos formas de acordar con el chofer la base de liquidación (#533).
 */
final class TripLegLiquidationBasisTest extends TestCase
{
    public function testPerTonneMultipliesTonnesByUnitPrice(): void
    {
        $basis = TripLegLiquidationBasis::perTonne(30, 45_000);

        self::assertSame(TripLegLiquidationBasis::POR_TONELADA, $basis->mode());
        self::assertSame('Por toneladas', $basis->label());
        self::assertSame(1_350_000.0, $basis->amount());
        self::assertSame(30.0, $basis->tonnes());
        self::assertSame(45_000.0, $basis->unitPrice());
    }

    public function testPerTonneWithoutBothPartsHasNoAmount(): void
    {
        self::assertNull(TripLegLiquidationBasis::perTonne(30, null)->amount());
        self::assertNull(TripLegLiquidationBasis::perTonne(null, 45_000)->amount());
        self::assertNull(TripLegLiquidationBasis::perTonne(null, null)->amount());
    }

    public function testAgreedAmountNeedsNoTonnesAndNeverInventsThem(): void
    {
        $basis = TripLegLiquidationBasis::agreedAmount(1_250_000);

        self::assertSame(TripLegLiquidationBasis::MONTO_ACORDADO, $basis->mode());
        self::assertSame('Monto acordado', $basis->label());
        self::assertSame(1_250_000.0, $basis->amount());
        // Ni cantidades ficticias ni precio unitario inverso.
        self::assertNull($basis->tonnes());
        self::assertNull($basis->unitPrice());
    }

    public function testFromModeIgnoresTonnesForAnAgreedAmount(): void
    {
        $basis = TripLegLiquidationBasis::fromMode('MONTO_ACORDADO', 30, 45_000, 1_250_000);

        self::assertNull($basis->tonnes());
        self::assertSame(1_250_000.0, $basis->amount());
    }

    public function testRejectsUnknownModeAndNegativeValues(): void
    {
        foreach ([
            static fn () => TripLegLiquidationBasis::fromMode('POR_KM', null, null, 10),
            static fn () => TripLegLiquidationBasis::perTonne(-1, 10),
            static fn () => TripLegLiquidationBasis::perTonne(1, -10),
            static fn () => TripLegLiquidationBasis::agreedAmount(-1),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Debió rechazar el acuerdo.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLegCommissionUsesThePerTonneBasis(): void
    {
        $leg = new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(2_000_000),
            new TripLegAmount(1_350_000),
            liquidationBasis: TripLegLiquidationBasis::perTonne(30, 45_000),
        );

        self::assertSame(189_000.0, $leg->commission(14.0));
        self::assertTrue($leg->liquidationBasis()->isPerTonne());
    }

    public function testLegRejectsALiquidationAmountThatDoesNotMatchTheBasis(): void
    {
        $this->expectException(DomainException::class);

        new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(2_000_000),
            new TripLegAmount(1_000_000),
            liquidationBasis: TripLegLiquidationBasis::perTonne(30, 45_000),
        );
    }

    public function testLegWithoutBasisIsAnAgreedAmountAsBefore(): void
    {
        $leg = new TripLeg('Rosario', 'Córdoba', new TripLegAmount(1_000_000), new TripLegAmount(800_000));

        self::assertSame(TripLegLiquidationBasis::MONTO_ACORDADO, $leg->liquidationBasis()->mode());
        self::assertSame(800_000.0, $leg->liquidationBasis()->amount());
    }

    public function testChangingTheInvoiceDoesNotChangeTheCommission(): void
    {
        $basis = TripLegLiquidationBasis::agreedAmount(1_250_000);
        $cheap = new TripLeg('Rosario', 'Córdoba', new TripLegAmount(1_500_000), new TripLegAmount(1_250_000), liquidationBasis: $basis);
        $expensive = new TripLeg('Rosario', 'Córdoba', new TripLegAmount(9_000_000, 'USD', 1250), new TripLegAmount(1_250_000), liquidationBasis: $basis);

        self::assertSame($cheap->commission(14.0), $expensive->commission(14.0));
        self::assertSame(175_000.0, $expensive->commission(14.0));
    }

    public function testPerTonneInForeignCurrencyIsConvertedWithThePactedRate(): void
    {
        $basis = TripLegLiquidationBasis::perTonne(30, 200);
        $leg = new TripLeg('Uruguaiana', 'Rosario', new TripLegAmount(9_000, 'USD', 1300), new TripLegAmount(6_000, 'BRL', 210), liquidationBasis: $basis);

        self::assertSame(6_000.0, $leg->liquidation()->amount());
        self::assertSame(1_260_000.0, $leg->liquidation()->amountArs());
        self::assertSame(176_400.0, $leg->commission(14.0));
    }
}
