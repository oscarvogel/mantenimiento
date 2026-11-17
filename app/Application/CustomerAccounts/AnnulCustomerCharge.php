<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerAccountRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Anula un cargo con un contraasiento (#530). El cargo y su asiento original no
 * se borran ni se editan: quedan como anulados, el libro suma el movimiento
 * opuesto y, si era de un tramo, el tramo vuelve a la cola de pendientes.
 */
final class AnnulCustomerCharge
{
    public function __construct(
        private readonly CustomerAccountRepository $accounts,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $chargeId, string $reason): void
    {
        $companyId = CustomerAccountAccess::formalize($actor);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Explicá por qué se anula el cargo.');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('El motivo admite hasta 500 caracteres.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $chargeId, $companyId, $reason): void {
            $charge = $this->accounts->findActiveChargeForUpdate($companyId, $chargeId);
            if ($charge === null) {
                throw new DomainException('El cargo no existe o ya está anulado.');
            }
            $this->accounts->annulCharge($companyId, $chargeId, $reason, $actor->userId(), $this->clock->now());
            CustomerAccountAudit::record(
                $this->audit, $actor, $companyId, null, AuditAction::CLIENTE_CC_CARGO_ANULADO, 'cliente_cc_cargos', $chargeId,
                ['estado' => 'VIGENTE', 'cliente_id' => $charge['cliente_id'], 'sentido' => $charge['sentido'], 'importe_ars' => $charge['importe_ars']],
                ['estado' => 'ANULADO'],
                $reason,
            );
        });
    }
}
