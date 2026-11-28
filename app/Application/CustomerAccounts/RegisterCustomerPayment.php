<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerPaymentRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\ChargeAllocation;
use App\Domain\CustomerAccounts\Currency;
use App\Domain\CustomerAccounts\CustomerPayment;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentImputation;
use DateTimeImmutable;
use DomainException;

/**
 * Registra un cobro de cliente (#588).
 *
 * El cobro se anota en su propia moneda, con la cotización congelada del
 * momento. Si se imputa contra documentos, tiene que ser en la misma moneda de
 * la deuda: no se compensan monedas distintas. Lo que no se imputa queda como
 * anticipo, es decir, saldo a favor del cliente en esa moneda.
 *
 * Todo el trabajo ocurre dentro de una transacción: el cobro, sus imputaciones
 * y los asientos del libro (el haber del cobro y las diferencias de cambio)
 * van juntos o no van nada.
 */
final class RegisterCustomerPayment
{
    public function __construct(
        private readonly CustomerPaymentRepository $payments,
        private readonly ExchangeRateCatalog $rates,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, RegisterCustomerPaymentCommand $command): int
    {
        $companyId = CustomerAccountAccess::cobrar($actor);
        $now = $this->clock->now();
        if ($command->date > $now) {
            throw new DomainException('La fecha del cobro no puede ser futura.');
        }

        $currency = Currency::fromCode($command->currency);
        $rate = RateResolver::resolve($this->rates, $companyId, $command->currency, $command->exchangeRate, $command->date);
        $amountMinor = Money::toMinor($command->amount);
        if ($amountMinor <= 0) {
            throw new DomainException('El importe del cobro debe ser mayor que cero.');
        }

        $payment = new CustomerPayment(
            customerId: $command->customerId,
            date: $command->date,
            currency: $currency,
            amountMinor: $amountMinor,
            rate: $rate,
            medium: $command->medium,
            state: $command->deferred ? CustomerPayment::PENDIENTE : CustomerPayment::VIGENTE,
            reference: $command->reference,
            notes: $command->notes,
        );

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $payment, $now): int {
            if (! $this->payments->customerExists($companyId, $command->customerId)) {
                throw new DomainException('El cliente no existe o no pertenece a la empresa.');
            }
            $key = $payment->activeKey();
            if ($key !== null && $this->payments->hasPaymentKey($companyId, $key)) {
                throw new DomainException('Ya hay un cobro registrado con esa referencia.');
            }

            $imputations = $this->prepareImputations($companyId, $command, $payment);
            $paymentId = $this->payments->addPayment($companyId, $payment, $actor->userId(), $now);
            $ids = $this->payments->addImputations($companyId, $command->customerId, $paymentId, $imputations, $actor->userId(), $now);

            $posted = [];
            foreach ($imputations as $index => $imputation) {
                $posted[] = ['id' => $ids[$index] ?? 0, 'imputacion' => $imputation];
            }
            if ($payment->isEffective()) {
                $this->payments->postPayment($companyId, $paymentId, $payment, $posted, $actor->userId(), $now);
            }

            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                null,
                AuditAction::CLIENTE_CC_COBRANZA_REGISTRADA,
                'cliente_cc_pagos',
                $paymentId,
                null,
                $this->snapshot($payment, $imputations),
            );

            return $paymentId;
        });
    }

    /**
     * Valida cada imputación contra el documento y calcula el reparto del valor
     * histórico que le corresponde.
     *
     * @return list<PaymentImputation>
     */
    private function prepareImputations(
        int $companyId,
        RegisterCustomerPaymentCommand $command,
        CustomerPayment $payment,
    ): array {
        $imputations = [];
        $total = 0;

        foreach ($command->imputations as $input) {
            $charge = $this->payments->findChargeForUpdate($companyId, $input->chargeId);
            if ($charge === null) {
                throw new DomainException('El documento no existe o no pertenece a la empresa.');
            }
            if ((int) $charge['cliente_id'] !== $command->customerId) {
                throw new DomainException('El documento pertenece a otro cliente.');
            }
            if ($charge['estado'] !== 'VIGENTE') {
                throw new DomainException('El documento está anulado: no admite cobros.');
            }
            if ((string) $charge['moneda'] !== $payment->currency()->code()) {
                throw new DomainException(
                    'El documento está en ' . $charge['moneda'] . ' y el cobro es en ' . $payment->currency()->code()
                    . ': no se compensan monedas distintas.',
                );
            }

            $amountMinor = Money::toMinor($input->amount);
            if ($amountMinor <= 0) {
                throw new DomainException('El importe imputado debe ser mayor que cero.');
            }

            $allocation = $this->allocationOf($charge);
            $pending = $allocation->pendingOriginalMinor() - (int) ($charge['reservado_original'] ?? 0);
            if ($amountMinor > $pending) {
                throw new DomainException('El cobro supera el saldo pendiente del documento.');
            }

            $imputations[] = new PaymentImputation(
                chargeId: (int) $charge['id'],
                currency: $payment->currency(),
                amountMinor: $amountMinor,
                historicalArsMinor: $allocation->allocate($amountMinor),
                chargeRate: $this->chargeRateOf($charge),
                paymentRate: $payment->rate(),
            );
            $total += $amountMinor;
        }

        if ($total > $payment->amountMinor()) {
            throw new DomainException('La suma de las imputaciones supera el importe del cobro.');
        }

        return $imputations;
    }

    /** @param array<string,mixed> $charge */
    private function allocationOf(array $charge): ChargeAllocation
    {
        return new ChargeAllocation(
            chargeOriginalMinor: Money::toMinor((string) $charge['importe_original']),
            chargeArsMinor: Money::toMinor((string) $charge['importe_ars']),
            allocatedOriginalMinor: Money::toMinor((string) $charge['pagado_original']),
            allocatedArsMinor: (int) ($charge['pagado_ars'] ?? 0),
        );
    }

    /**
     * Cotización congelada del documento, tal como quedó registrada.
     *
     * @param array<string,mixed> $charge
     */
    private function chargeRateOf(array $charge): FrozenExchangeRate
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $charge['cotizacion_fecha']);
        if ($date === false) {
            $date = new DateTimeImmutable('1970-01-01');
        }

        return new FrozenExchangeRate(
            (string) $charge['moneda'],
            (float) $charge['cotizacion'],
            $date,
            (string) $charge['cotizacion_fuente'],
        );
    }

    /**
     * @param list<PaymentImputation> $imputations
     * @return array<string,mixed>
     */
    private function snapshot(CustomerPayment $payment, array $imputations): array
    {
        return [
            'cliente_id' => $payment->customerId(),
            'fecha' => $payment->date()->format('Y-m-d'),
            'moneda' => $payment->currency()->code(),
            'importe' => Money::format($payment->amountMinor()),
            'cotizacion' => $payment->rate()->rate(),
            'cotizacion_fecha' => $payment->rate()->date()->format('Y-m-d'),
            'importe_ars' => Money::format($payment->amountArsMinor()),
            'medio' => $payment->medium(),
            'estado' => $payment->state(),
            'referencia' => $payment->reference(),
            'imputaciones' => array_map(
                static fn (PaymentImputation $imputation): array => [
                    'cargo_id' => $imputation->chargeId(),
                    'importe_original' => Money::format($imputation->amountMinor()),
                    'importe_ars_cargo' => Money::format($imputation->historicalArsMinor()),
                    'diferencia_cambio' => Money::format($imputation->difference()->amountMinor()),
                ],
                $imputations,
            ),
        ];
    }
}
