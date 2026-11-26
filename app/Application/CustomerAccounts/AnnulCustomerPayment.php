<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerPaymentRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Anula un cobro de cliente (#588).
 *
 * Anular no borra nada: marca el cobro y libera su clave de idempotencia, y
 * deja el contraasiento que restituye la deuda en la moneda original. Si el
 * cobro impactó en el libro, también se revierten las diferencias de cambio
 * asociadas, con los mismos importes y en el mismo sentido inverso.
 */
final class AnnulCustomerPayment
{
    public function __construct(
        private readonly CustomerPaymentRepository $payments,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $paymentId, string $reason): int
    {
        $companyId = CustomerAccountAccess::cobrar($actor);
        $now = $this->clock->now();
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Indicá el motivo de la anulación.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $companyId, $paymentId, $reason, $now): int {
            $record = $this->payments->findPaymentForUpdate($companyId, $paymentId);
            if ($record === null) {
                throw new DomainException('El cobro no existe o no pertenece a la empresa.');
            }
            $payment = $record['pago'];
            if ($payment->isAnnulled()) {
                throw new DomainException('El cobro ya está anulado.');
            }

            if ($payment->isEffective()) {
                $this->payments->reversePayment($companyId, $paymentId, $actor->userId(), $now);
            }
            $this->payments->annulPayment($companyId, $paymentId, $reason, $actor->userId(), $now);

            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                null,
                AuditAction::CLIENTE_CC_COBRANZA_ANULADA,
                'cliente_cc_pagos',
                $paymentId,
                ['estado' => $payment->state()],
                ['estado' => $payment->annul()->state()],
                $reason,
            );

            return $paymentId;
        });
    }
}
