<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerPaymentRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\ChargeAllocation;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentImputation;
use DomainException;

/**
 * Acredita un cobro diferido (#588).
 *
 * Es el camino de una transferencia que se verificó o de un cheque que se
 * recibió: hasta entonces el cobro estaba anotado pero no impactaba en la
 * cuenta corriente. Al acreditarlo se revalida cada imputación contra el
 * documento —que pudo haber cambiado de saldo mientras tanto— y recién ahí se
 * asienta el haber y las diferencias de cambio.
 */
final class CreditCustomerPayment
{
    public function __construct(
        private readonly CustomerPaymentRepository $payments,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $paymentId): int
    {
        $companyId = CustomerAccountAccess::cobrar($actor);
        $now = $this->clock->now();

        return $this->unitOfWork->transactional(function () use ($actor, $companyId, $paymentId, $now): int {
            $record = $this->payments->findPaymentForUpdate($companyId, $paymentId);
            if ($record === null) {
                throw new DomainException('El cobro no existe o no pertenece a la empresa.');
            }
            $payment = $record['pago'];
            if ($payment->isAnnulled()) {
                throw new DomainException('El cobro está anulado.');
            }
            if ($payment->isEffective()) {
                throw new DomainException('El cobro ya impacta en la cuenta corriente.');
            }

            $imputations = [];
            foreach ($record['imputaciones'] as $entry) {
                $imputation = $entry['imputacion'];
                $charge = $this->payments->findChargeForUpdate($companyId, $imputation->chargeId());
                if ($charge === null) {
                    throw new DomainException('El documento del cobro no existe o no pertenece a la empresa.');
                }
                if ($charge['estado'] !== 'VIGENTE') {
                    throw new DomainException('El documento está anulado: no admite cobros.');
                }
                if ((string) $charge['moneda'] !== $payment->currency()->code()) {
                    throw new DomainException('El documento está en otra moneda: no se compensan monedas distintas.');
                }

                $allocation = $this->allocationOf($charge);
                if ($imputation->amountMinor() > $allocation->pendingOriginalMinor()) {
                    throw new DomainException('El cobro supera el saldo pendiente del documento.');
                }

                $updated = new PaymentImputation(
                    chargeId: $imputation->chargeId(),
                    currency: $payment->currency(),
                    amountMinor: $imputation->amountMinor(),
                    historicalArsMinor: $allocation->allocate($imputation->amountMinor()),
                    chargeRate: $imputation->chargeRate(),
                    paymentRate: $payment->rate(),
                );
                $this->payments->updateImputationAllocation($companyId, $entry['id'], $updated, $actor->userId(), $now);
                $imputations[] = ['id' => $entry['id'], 'imputacion' => $updated];
            }

            $this->payments->creditPayment($companyId, $paymentId, $actor->userId(), $now);
            $this->payments->postPayment($companyId, $paymentId, $payment->credit(), $imputations, $actor->userId(), $now);

            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                null,
                AuditAction::CLIENTE_CC_COBRANZA_ACREDITADA,
                'cliente_cc_pagos',
                $paymentId,
                ['estado' => $payment->state()],
                ['estado' => $payment->credit()->state()],
            );

            return $paymentId;
        });
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
}
