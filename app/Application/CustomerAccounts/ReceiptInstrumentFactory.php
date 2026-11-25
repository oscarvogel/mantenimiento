<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentInstrument;
use DateTimeImmutable;
use DomainException;

/**
 * Convierte las líneas del formulario en medios de pago validados y con la
 * cotización histórica congelada. Se ejecuta dentro de la transacción del
 * recibo; un cambio posterior del catálogo no modifica lo ya registrado.
 */
final class ReceiptInstrumentFactory
{
    public static function build(
        ExchangeRateCatalog $rates,
        int $companyId,
        ReceiptInstrumentInput $input,
        DateTimeImmutable $rateDate,
        DateTimeImmutable $today,
    ): PaymentInstrument {
        $kind = strtoupper(trim($input->kind));
        if (! in_array($kind, PaymentInstrument::KINDS, true)) {
            throw new DomainException('Elegí un medio de pago válido en cada línea.');
        }
        $currency = strtoupper(trim($input->currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda de cada medio de pago debe tener tres letras.');
        }
        $amountMinor = Money::toMinor($input->amount);
        if ($amountMinor <= 0) {
            throw new DomainException('El importe de cada medio de pago debe ser mayor que cero.');
        }
        $informedRate = $input->rate === null || trim($input->rate) === ''
            ? null
            : Money::toDecimal(Money::toMinor($input->rate, Money::RATE_SCALE), Money::RATE_SCALE);
        $rate = RateResolver::resolve($rates, $companyId, $currency, $informedRate, $rateDate);

        $notes = $input->notes;

        return match ($kind) {
            PaymentInstrument::EFECTIVO => PaymentInstrument::cash($rate, $amountMinor, $notes),
            PaymentInstrument::TRANSFERENCIA => PaymentInstrument::transfer(
                $rate,
                $amountMinor,
                (string) $input->bank,
                $input->account,
                $input->holder,
                $input->transferDate ?? throw new DomainException('Indicá la fecha de la transferencia.'),
                (string) $input->reference,
                $input->verification,
                $notes,
            ),
            PaymentInstrument::CHEQUE,
            PaymentInstrument::E_CHEQ => PaymentInstrument::cheque(
                $kind,
                $rate,
                $amountMinor,
                (string) $input->bank,
                (string) $input->chequeNumber,
                (string) $input->drawer,
                $input->drawerTaxId,
                $input->chequeIssueDate ?? throw new DomainException('Indicá la fecha de emisión del cheque.'),
                $input->chequeDueDate ?? throw new DomainException('Indicá la fecha de pago del cheque.'),
                $input->chequeReceivedDate ?? throw new DomainException('Indicá la fecha de recepción del cheque.'),
                $notes,
            ),
        };
    }

    /** @param list<ReceiptImputationInput> $inputs @return list<\App\Domain\CustomerAccounts\ReceiptImputation> */
    public static function imputations(array $inputs): array
    {
        $result = [];
        $seen = [];
        foreach ($inputs as $input) {
            if ($input->chargeId <= 0) {
                continue;
            }
            if (isset($seen[$input->chargeId])) {
                throw new DomainException('Un mismo documento no puede repetirse en las imputaciones.');
            }
            $seen[$input->chargeId] = true;
            $result[] = \App\Domain\CustomerAccounts\ReceiptImputation::fromDecimal($input->chargeId, $input->amount);
        }

        return $result;
    }

    /** @param list<\App\Domain\CustomerAccounts\ReceiptImputation> $imputations */
    public static function totalsByCharge(array $imputations): array
    {
        $totals = [];
        foreach ($imputations as $imputation) {
            $totals[$imputation->chargeId()] = ($totals[$imputation->chargeId()] ?? 0) + $imputation->amountMinor();
        }

        return $totals;
    }
}
