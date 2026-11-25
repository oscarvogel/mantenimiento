<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerAccounts;

use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentInstrument;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PaymentInstrumentTest extends TestCase
{
    public function testCashAccreditsOnConfirmationAndFreezesItsRate(): void
    {
        $cash = PaymentInstrument::cash($this->usd(1500), Money::toMinor('500'));

        self::assertTrue($cash->accreditsOnConfirmation());
        self::assertSame('USD', $cash->currency());
        self::assertSame(750_000.0, $cash->amountArs());
        self::assertSame(1500.0, $cash->rate()->rate());
    }

    public function testTransferOnlyAccreditsWhenVerifiedOrConciled(): void
    {
        $pending = PaymentInstrument::transfer($this->ars(), Money::toMinor('1000'), 'Banco Nación', null, null, new DateTimeImmutable('2026-11-20'), 'TRF-1');
        $verified = PaymentInstrument::transfer($this->ars(), Money::toMinor('1000'), 'Banco Nación', null, null, new DateTimeImmutable('2026-11-20'), 'TRF-2', PaymentInstrument::VERIFICATION_VERIFIED);
        $concilied = PaymentInstrument::transfer($this->ars(), Money::toMinor('1000'), 'Banco Nación', null, null, new DateTimeImmutable('2026-11-20'), 'TRF-3', PaymentInstrument::VERIFICATION_CONCILIED);

        self::assertFalse($pending->accreditsOnConfirmation());
        self::assertTrue($verified->accreditsOnConfirmation());
        self::assertTrue($concilied->accreditsOnConfirmation());
    }

    public function testTransferRequiresBankDateAndReference(): void
    {
        $this->expectException(DomainException::class);
        PaymentInstrument::transfer($this->ars(), Money::toMinor('1000'), '', null, null, new DateTimeImmutable('2026-11-20'), '');
    }

    public function testChequeNeverAccreditsOnConfirmationAndRequiresItsIdentity(): void
    {
        $cheque = PaymentInstrument::cheque(
            PaymentInstrument::CHEQUE,
            $this->ars(),
            Money::toMinor('1500'),
            'Banco Galicia',
            '000123',
            'Grupo Pluma',
            '30-11111111-1',
            new DateTimeImmutable('2026-11-01'),
            new DateTimeImmutable('2026-12-01'),
            new DateTimeImmutable('2026-11-20'),
        );
        self::assertFalse($cheque->accreditsOnConfirmation());
        self::assertTrue($cheque->isCheque());

        $this->expectException(DomainException::class);
        PaymentInstrument::cheque(
            PaymentInstrument::E_CHEQ,
            $this->ars(),
            Money::toMinor('1500'),
            'Banco Galicia',
            '',
            'Grupo Pluma',
            null,
            new DateTimeImmutable('2026-11-01'),
            new DateTimeImmutable('2026-12-01'),
            new DateTimeImmutable('2026-11-20'),
        );
    }

    public function testChequeDueDateCannotPrecedeIssueDate(): void
    {
        $this->expectExceptionMessage('no puede ser anterior');
        PaymentInstrument::cheque(
            PaymentInstrument::CHEQUE,
            $this->ars(),
            Money::toMinor('1500'),
            'Banco Galicia',
            '000123',
            'Grupo Pluma',
            null,
            new DateTimeImmutable('2026-12-01'),
            new DateTimeImmutable('2026-11-01'),
            new DateTimeImmutable('2026-11-20'),
        );
    }

    public function testRejectsZeroAmount(): void
    {
        $this->expectExceptionMessage('mayor que cero');
        PaymentInstrument::cash($this->ars(), 0);
    }

    private function ars(): FrozenExchangeRate
    {
        return new FrozenExchangeRate('ARS', 1, new DateTimeImmutable('2026-11-20'), FrozenExchangeRate::SOURCE_PESOS);
    }

    private function usd(float $rate): FrozenExchangeRate
    {
        return new FrozenExchangeRate('USD', $rate, new DateTimeImmutable('2026-11-20'), FrozenExchangeRate::SOURCE_MANUAL);
    }
}
