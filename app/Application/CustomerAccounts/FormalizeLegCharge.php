<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\BillableLegLookup;
use App\Application\CustomerAccounts\Port\CustomerAccountRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\ChargeDocument;
use App\Domain\CustomerAccounts\CustomerCharge;
use DomainException;

/**
 * Formaliza el cobro de un tramo y hace nacer la deuda del cliente (#530).
 *
 * Es el único lugar donde un tramo genera Debe en la cuenta corriente: cerrar el
 * viaje no lo hace. La cotización que se informa queda congelada con la fecha
 * del documento; si no se informa, se toma la vigente del catálogo de la
 * empresa y queda registrado que vino de ahí.
 */
final class FormalizeLegCharge
{
    public function __construct(
        private readonly BillableLegLookup $legs,
        private readonly CustomerAccountRepository $accounts,
        private readonly ExchangeRateCatalog $rates,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, FormalizeLegChargeCommand $command): int
    {
        $companyId = CustomerAccountAccess::formalize($actor);
        $branchIds = CustomerAccountAccess::branches($actor);
        if ($command->documentDate > $this->clock->now()) {
            throw new DomainException('La fecha del documento no puede ser futura.');
        }
        $rate = RateResolver::resolve($this->rates, $companyId, $command->currency, $command->exchangeRate, $command->documentDate);
        $document = new ChargeDocument($command->modality, $command->documentDate, $command->documentNumber, $command->invoiceType);

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds, $rate, $document): int {
            $leg = $this->legs->findForUpdate($companyId, $branchIds, $command->legId);
            if ($leg === null) {
                throw new DomainException('El tramo solicitado no existe o no está autorizado.');
            }
            $charge = CustomerCharge::forLeg($leg, $document, $command->amount, $rate, $command->notes);
            if ($this->accounts->hasActiveCharge($companyId, $charge->activeKey())) {
                throw new DomainException('El tramo ya tiene el cobro formalizado. Para corregirlo, anulá el cargo y volvé a formalizarlo.');
            }
            if ($this->accounts->hasActiveNonCollectible($companyId, $charge->activeKey())) {
                throw new DomainException('El tramo está marcado como no cobrable. Reabrilo antes de formalizar el cobro.');
            }

            $chargeId = $this->accounts->addCharge($companyId, $charge, $actor->userId(), $this->clock->now());
            CustomerAccountAudit::record($this->audit, $actor, $companyId, $leg->branchId(), AuditAction::CLIENTE_CC_CARGO_REGISTRADO, 'cliente_cc_cargos', $chargeId, null, ChargeSnapshot::of($charge));

            return $chargeId;
        });
    }
}
