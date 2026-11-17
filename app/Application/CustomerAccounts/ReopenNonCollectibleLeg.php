<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\BillableLegLookup;
use App\Application\CustomerAccounts\Port\CustomerAccountRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\Identity\ActorContext;
use DomainException;

/** Devuelve a pendientes un tramo marcado como no cobrable, dejando el historial. */
final class ReopenNonCollectibleLeg
{
    public function __construct(
        private readonly BillableLegLookup $legs,
        private readonly CustomerAccountRepository $accounts,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $legId, string $reason): void
    {
        $companyId = CustomerAccountAccess::formalize($actor);
        $branchIds = CustomerAccountAccess::branches($actor);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Explicá por qué se reabre el tramo.');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('El motivo admite hasta 500 caracteres.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $legId, $reason, $companyId, $branchIds): void {
            $leg = $this->legs->findForUpdate($companyId, $branchIds, $legId);
            if ($leg === null) {
                throw new DomainException('El tramo solicitado no existe o no está autorizado.');
            }
            if (! $this->accounts->reopenNonCollectible($companyId, $legId, $reason, $actor->userId(), $this->clock->now())) {
                throw new DomainException('El tramo no está marcado como no cobrable.');
            }
            CustomerAccountAudit::record(
                $this->audit, $actor, $companyId, $leg->branchId(), AuditAction::CLIENTE_CC_TRAMO_REABIERTO, 'viaje_operativo_tramos', $legId,
                ['estado_comercial' => 'NO_COBRABLE'], ['estado_comercial' => 'PENDIENTE'], $reason,
            );
        });
    }
}
