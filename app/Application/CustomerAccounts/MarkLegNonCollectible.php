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
use App\Domain\CustomerAccounts\NonCollectibleClosure;
use DomainException;

/** Cierra comercialmente un tramo sin generar deuda, con motivo (#530). */
final class MarkLegNonCollectible
{
    public function __construct(
        private readonly BillableLegLookup $legs,
        private readonly CustomerAccountRepository $accounts,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, int $legId, string $reason): int
    {
        $companyId = CustomerAccountAccess::formalize($actor);
        $branchIds = CustomerAccountAccess::branches($actor);

        return $this->unitOfWork->transactional(function () use ($actor, $legId, $reason, $companyId, $branchIds): int {
            $leg = $this->legs->findForUpdate($companyId, $branchIds, $legId);
            if ($leg === null) {
                throw new DomainException('El tramo solicitado no existe o no está autorizado.');
            }
            $closure = new NonCollectibleClosure($leg, $reason);
            if ($this->accounts->hasActiveCharge($companyId, $closure->activeKey())) {
                throw new DomainException('El tramo ya tiene el cobro formalizado. Anulá el cargo antes de marcarlo como no cobrable.');
            }
            if ($this->accounts->hasActiveNonCollectible($companyId, $closure->activeKey())) {
                throw new DomainException('El tramo ya está marcado como no cobrable.');
            }

            $closureId = $this->accounts->addNonCollectible($companyId, $closure, $actor->userId(), $this->clock->now());
            CustomerAccountAudit::record(
                $this->audit, $actor, $companyId, $leg->branchId(), AuditAction::CLIENTE_CC_TRAMO_NO_COBRABLE, 'viaje_operativo_tramos', $leg->legId(),
                null, ['estado_comercial' => 'NO_COBRABLE', 'viaje_id' => $leg->tripId()], $closure->reason(),
            );

            return $closureId;
        });
    }
}
