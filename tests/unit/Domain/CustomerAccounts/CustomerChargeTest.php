<?php

declare(strict_types=1);

use App\Domain\CustomerAccounts\BillableLeg;
use App\Domain\CustomerAccounts\ChargeDocument;
use App\Domain\CustomerAccounts\CustomerCharge;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\NonCollectibleClosure;
use PHPUnit\Framework\TestCase;

/** Reglas de #530 que no dependen de base ni framework. */
final class CustomerChargeTest extends TestCase
{
    public function testAuthorizedMemoFreezesItsOwnRateNotTheTripEstimate(): void
    {
        // Viaje USD 2.800 estimado a 1.530; el memo se autoriza a 1.578.
        $charge = CustomerCharge::forLeg(
            $this->leg(estimatedRate: 1530.0),
            new ChargeDocument(CustomerCharge::MEMO_AUTORIZADO, new DateTimeImmutable('2026-11-10'), 'M-77'),
            2800.0,
            new FrozenExchangeRate('USD', 1578.0, new DateTimeImmutable('2026-11-10'), FrozenExchangeRate::SOURCE_MANUAL),
        );

        self::assertSame(4_418_400.0, $charge->amountArs());
        self::assertSame(4_418_400.0, $charge->debitArs());
        self::assertSame(0.0, $charge->creditArs());
        self::assertSame('TRAMO:9', $charge->activeKey());
    }

    public function testLowerRateAtInvoiceGivesItsOwnAmount(): void
    {
        $charge = CustomerCharge::forLeg(
            $this->leg(),
            new ChargeDocument(CustomerCharge::FACTURA_EMITIDA, new DateTimeImmutable('2026-11-10'), '0003-00001234', 'FACTURA_A'),
            2800.0,
            new FrozenExchangeRate('USD', 1490.0, new DateTimeImmutable('2026-11-10'), FrozenExchangeRate::SOURCE_MANUAL),
        );

        self::assertSame(4_172_000.0, $charge->amountArs());
    }

    public function testPesosAlwaysUseRateOne(): void
    {
        $rate = new FrozenExchangeRate('ARS', 999.0, new DateTimeImmutable('2026-11-10'), FrozenExchangeRate::SOURCE_MANUAL);

        self::assertSame(1.0, $rate->rate());
        self::assertSame(FrozenExchangeRate::SOURCE_PESOS, $rate->source());
        self::assertSame(1234.57, $rate->toArs(1234.565));
    }

    public function testInternalClosingNeedsNoDocumentNumber(): void
    {
        $charge = CustomerCharge::forLeg(
            $this->leg(),
            new ChargeDocument(CustomerCharge::CIERRE_COMERCIAL_APROBADO, new DateTimeImmutable('2026-11-10')),
            1000.0,
            new FrozenExchangeRate('ARS', 1.0, new DateTimeImmutable('2026-11-10'), FrozenExchangeRate::SOURCE_PESOS),
        );

        self::assertNull($charge->document()->number());
        self::assertSame(1000.0, $charge->amountArs());
    }

    public function testMemoRequiresItsReference(): void
    {
        $this->expectExceptionMessage('memo autorizado');
        new ChargeDocument(CustomerCharge::MEMO_AUTORIZADO, new DateTimeImmutable('2026-11-10'), '  ');
    }

    public function testInvoiceRequiresTypeAndNumber(): void
    {
        $this->expectExceptionMessage('tipo de factura');
        new ChargeDocument(CustomerCharge::FACTURA_EMITIDA, new DateTimeImmutable('2026-11-10'), '0003-1', null);
    }

    public function testUnknownModalityIsRejected(): void
    {
        $this->expectExceptionMessage('Elegí cómo se formaliza');
        new ChargeDocument('POR_DEFINIR', new DateTimeImmutable('2026-11-10'), 'X');
    }

    public function testLegOfAnOpenTripCannotBeFormalized(): void
    {
        $this->expectExceptionMessage('viajes finalizados');
        CustomerCharge::forLeg(
            $this->leg(finished: false),
            new ChargeDocument(CustomerCharge::CIERRE_COMERCIAL_APROBADO, new DateTimeImmutable('2026-11-10')),
            1000.0,
            new FrozenExchangeRate('ARS', 1.0, new DateTimeImmutable('2026-11-10'), FrozenExchangeRate::SOURCE_PESOS),
        );
    }

    public function testLegWithoutCustomerCannotBeFormalized(): void
    {
        $this->expectExceptionMessage('no tiene cliente');
        CustomerCharge::forLeg(
            $this->leg(customerId: null),
            new ChargeDocument(CustomerCharge::CIERRE_COMERCIAL_APROBADO, new DateTimeImmutable('2026-11-10')),
            1000.0,
            new FrozenExchangeRate('ARS', 1.0, new DateTimeImmutable('2026-11-10'), FrozenExchangeRate::SOURCE_PESOS),
        );
    }

    public function testOpeningBalanceInFavourGoesToCredit(): void
    {
        $charge = CustomerCharge::openingBalance(
            4,
            CustomerCharge::HABER,
            new ChargeDocument(CustomerCharge::SALDO_INICIAL, new DateTimeImmutable('2026-11-01'), 'Resumen octubre'),
            150_000.0,
            new FrozenExchangeRate('ARS', 1.0, new DateTimeImmutable('2026-11-01'), FrozenExchangeRate::SOURCE_PESOS),
        );

        self::assertSame(0.0, $charge->debitArs());
        self::assertSame(150_000.0, $charge->creditArs());
        self::assertSame('SALDO_INICIAL:4', $charge->activeKey());
        self::assertNull($charge->leg());
    }

    public function testOpeningBalanceCannotUseALegModality(): void
    {
        $this->expectExceptionMessage('propia modalidad');
        CustomerCharge::openingBalance(
            4,
            CustomerCharge::DEBE,
            new ChargeDocument(CustomerCharge::MEMO_AUTORIZADO, new DateTimeImmutable('2026-11-01'), 'M-1'),
            10.0,
            new FrozenExchangeRate('ARS', 1.0, new DateTimeImmutable('2026-11-01'), FrozenExchangeRate::SOURCE_PESOS),
        );
    }

    public function testAmountMustBePositive(): void
    {
        $this->expectExceptionMessage('mayor que cero');
        CustomerCharge::forLeg(
            $this->leg(),
            new ChargeDocument(CustomerCharge::CIERRE_COMERCIAL_APROBADO, new DateTimeImmutable('2026-11-10')),
            0.0,
            new FrozenExchangeRate('ARS', 1.0, new DateTimeImmutable('2026-11-10'), FrozenExchangeRate::SOURCE_PESOS),
        );
    }

    public function testNonCollectibleNeedsAReasonAndAFinishedTrip(): void
    {
        $closure = new NonCollectibleClosure($this->leg(customerId: null), 'Viaje de cortesía acordado con gerencia');
        self::assertSame('TRAMO:9', $closure->activeKey());

        $this->expectExceptionMessage('por qué');
        new NonCollectibleClosure($this->leg(), 'no');
    }

    private function leg(bool $finished = true, ?int $customerId = 4, ?float $estimatedRate = 1530.0): BillableLeg
    {
        return new BillableLeg(9, 16, 2, $customerId, $finished, 2800.0, 'USD', $estimatedRate, 2800.0 * ($estimatedRate ?? 1));
    }
}
