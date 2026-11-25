<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerAccounts;

use App\Domain\CustomerAccounts\CustomerReceipt;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentInstrument;
use App\Domain\CustomerAccounts\ReceiptImputation;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CustomerReceiptTest extends TestCase
{
    public function testDraftKeepsTotalInArsAndChecksImputationsAgainstIt(): void
    {
        $receipt = CustomerReceipt::draft(
            5, 2, 4, 'R',
            new DateTimeImmutable('2026-11-20'),
            new DateTimeImmutable('2026-11-20'),
            'Cobranza combinada',
            [$this->cash('500'), $this->cash('1000', 'USD', 1500)],
            [new ReceiptImputation(31, Money::toMinor('1000'))],
            new DateTimeImmutable('2026-11-25'),
        );

        self::assertSame(CustomerReceipt::DRAFT, $receipt->status());
        self::assertSame('Borrador', $receipt->displayNumber());
        self::assertSame(1_500_500.0, $receipt->total());
        self::assertSame(1_500_500.0, $receipt->creditableOnConfirmation());
        self::assertSame(0.0, Money::toDecimal($receipt->pendingAccreditationMinor()));
        self::assertSame(1_000.0, $receipt->imputationsTotal());
    }

    public function testChequeDoesNotCountAsCreditableOnConfirmation(): void
    {
        $receipt = $this->draft([$this->cash('500'), $this->cheque('1500')], []);

        self::assertSame(2_000.0, $receipt->total());
        self::assertSame(500.0, $receipt->creditableOnConfirmation());
        self::assertEquals(1500.0, $receipt->pendingAccreditationMinor() / 100);
    }

    public function testCannotConfirmImputationsBeyondWhatIsCredited(): void
    {
        $receipt = $this->draft([$this->cash('500'), $this->cheque('1500')], [new ReceiptImputation(31, Money::toMinor('1000'))]);

        $this->expectExceptionMessage('supera el importe que se acredita');
        $receipt->confirm(1, new DateTimeImmutable('2026-11-25 10:00:00'), 1);
    }

    public function testConfirmConsumesNumberAndAnnulKeepsTheOriginal(): void
    {
        $receipt = $this->draft([$this->cash('500')], [new ReceiptImputation(31, Money::toMinor('500'))]);

        $receipt->confirm(7, new DateTimeImmutable('2026-11-25 10:00:00'), 1);
        self::assertSame(CustomerReceipt::CONFIRMED, $receipt->status());
        self::assertSame('R-000007', $receipt->displayNumber());
        self::assertNotNull($receipt->confirmedAt());

        $receipt->annul('El cliente devolvió el pago', new DateTimeImmutable('2026-11-25 11:00:00'), 1);
        self::assertSame(CustomerReceipt::CANCELLED, $receipt->status());
        self::assertSame('El cliente devolvió el pago', $receipt->cancelReason());

        $this->expectExceptionMessage('ya está anulado');
        $receipt->annul('Segundo intento inválido', new DateTimeImmutable('2026-11-25 12:00:00'), 1);
    }

    public function testDraftCannotBeAnnulledAndFutureDatesAreRejected(): void
    {
        $receipt = $this->draft([$this->cash('500')], []);
        try {
            $receipt->annul('No corresponde anular un borrador', new DateTimeImmutable('2026-11-25'), 1);
            self::fail('Un borrador se descarta, no se anula.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('borrador', $exception->getMessage());
        }

        $this->expectExceptionMessage('no puede ser futura');
        CustomerReceipt::draft(
            5, 2, 4, 'R',
            new DateTimeImmutable('2026-12-01'),
            new DateTimeImmutable('2026-12-01'),
            null,
            [$this->cash('500')],
            [],
            new DateTimeImmutable('2026-11-25'),
        );
    }

    public function testDuplicateChargeInImputationsIsRejectedByTheFactory(): void
    {
        $this->expectExceptionMessage('no puede repetirse');
        \App\Application\CustomerAccounts\ReceiptInstrumentFactory::imputations([
            new \App\Application\CustomerAccounts\ReceiptImputationInput(31, '100'),
            new \App\Application\CustomerAccounts\ReceiptImputationInput(31, '200'),
        ]);
    }

    /** @param list<PaymentInstrument> $instruments @param list<ReceiptImputation> $imputations */
    private function draft(array $instruments, array $imputations): CustomerReceipt
    {
        return CustomerReceipt::draft(
            5, 2, 4, 'R',
            new DateTimeImmutable('2026-11-20'),
            new DateTimeImmutable('2026-11-20'),
            null,
            $instruments,
            $imputations,
            new DateTimeImmutable('2026-11-25'),
        );
    }

    private function cash(string $amount, string $currency = 'ARS', float $rate = 1): PaymentInstrument
    {
        return PaymentInstrument::cash($this->rate($currency, $rate), Money::toMinor($amount));
    }

    private function cheque(string $amount): PaymentInstrument
    {
        return PaymentInstrument::cheque(
            PaymentInstrument::CHEQUE,
            $this->rate('ARS', 1),
            Money::toMinor($amount),
            'Banco Galicia',
            '000123',
            'Grupo Pluma',
            null,
            new DateTimeImmutable('2026-11-01'),
            new DateTimeImmutable('2026-12-01'),
            new DateTimeImmutable('2026-11-20'),
        );
    }

    private function rate(string $currency, float $rate): FrozenExchangeRate
    {
        return new FrozenExchangeRate($currency, $rate, new DateTimeImmutable('2026-11-20'), $currency === 'ARS' ? FrozenExchangeRate::SOURCE_PESOS : FrozenExchangeRate::SOURCE_MANUAL);
    }
}
