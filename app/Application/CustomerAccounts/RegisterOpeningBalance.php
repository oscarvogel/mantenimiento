<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerAccountRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\ChargeDocument;
use App\Domain\CustomerAccounts\CustomerCharge;
use DomainException;

/**
 * Registra el saldo con el que un cliente abre la cuenta corriente: a cobrar o
 * a favor del cliente, con fecha y referencia de respaldo. Uno solo vigente por
 * cliente; para corregirlo se anula y se vuelve a cargar.
 */
final class RegisterOpeningBalance
{
    public function __construct(
        private readonly CustomerAccountRepository $accounts,
        private readonly ExchangeRateCatalog $rates,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, RegisterOpeningBalanceCommand $command): int
    {
        $companyId = CustomerAccountAccess::formalize($actor);
        if ($command->date > $this->clock->now()) {
            throw new DomainException('La fecha del saldo inicial no puede ser futura.');
        }
        $rate = RateResolver::resolve($this->rates, $companyId, $command->currency, $command->exchangeRate, $command->date);
        $document = new ChargeDocument(CustomerCharge::SALDO_INICIAL, $command->date, $command->reference);

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $rate, $document): int {
            if (! $this->accounts->customerExists($companyId, $command->customerId)) {
                throw new DomainException('El cliente no existe o no pertenece a la empresa.');
            }
            $charge = CustomerCharge::openingBalance($command->customerId, $command->side, $document, $command->amount, $rate, $command->notes);
            if ($this->accounts->hasActiveCharge($companyId, $charge->activeKey())) {
                throw new DomainException('El cliente ya tiene un saldo inicial. Para corregirlo, anulalo y volvé a cargarlo.');
            }

            $chargeId = $this->accounts->addCharge($companyId, $charge, $actor->userId(), $this->clock->now());
            CustomerAccountAudit::record($this->audit, $actor, $companyId, null, AuditAction::CLIENTE_CC_CARGO_REGISTRADO, 'cliente_cc_cargos', $chargeId, null, ChargeSnapshot::of($charge));

            return $chargeId;
        });
    }
}
