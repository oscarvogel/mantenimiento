<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\CustomerReceipt;
use App\Domain\CustomerAccounts\Money;
use DomainException;

/**
 * Imputa el crédito disponible de un recibo confirmado a documentos del mismo
 * cliente (#536). No crea movimientos: el Haber ya nació al acreditarse el
 * medio. Tampoco permite imputar entre clientes ni por encima de lo disponible.
 */
final class ImputeCustomerPayment
{
    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, ImputeCustomerPaymentCommand $command): void
    {
        $companyId = CustomerReceiptAccess::impute($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $imputations = ReceiptInstrumentFactory::imputations($command->imputations);
        if ($imputations === []) {
            throw new DomainException('Elegí al menos un documento y un importe para imputar.');
        }
        $now = $this->clock->now();

        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds, $imputations, $now): void {
            $receipt = $this->receipts->findForUpdate($companyId, $command->receiptId);
            if ($receipt === null || ($branchIds !== null && ! in_array((int) $receipt['sucursal_id'], $branchIds, true))) {
                throw new DomainException('El recibo no existe o no está autorizado.');
            }
            if ((string) $receipt['estado'] === CustomerReceipt::CANCELLED) {
                throw new DomainException('El recibo está anulado: no se puede imputar.');
            }
            if ((string) $receipt['estado'] !== CustomerReceipt::CONFIRMED) {
                throw new DomainException('Confirmá el recibo antes de imputar su pago.');
            }

            $totals = $this->receipts->receiptTotals($companyId, $command->receiptId);
            $availableMinor = Money::toMinor((float) $totals['disponible_ars']);
            if ($availableMinor <= 0) {
                throw new DomainException('El recibo no tiene saldo disponible para imputar.');
            }
            ReceiptImputationGuard::validate($this->receipts, $companyId, (int) $receipt['cliente_id'], $imputations, $availableMinor);

            $this->receipts->addImputations($companyId, $command->receiptId, $imputations, $now, $actor->userId());
            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                (int) $receipt['sucursal_id'],
                AuditAction::CLIENTE_RECIBO_PAGO_IMPUTADO,
                'cliente_recibos',
                $command->receiptId,
                ['imputado_ars' => (float) $totals['imputado_ars'], 'disponible_ars' => (float) $totals['disponible_ars']],
                [
                    'imputaciones' => array_map(static fn ($imputation): array => $imputation->snapshot(), $imputations),
                    'imputado_ars' => Money::toDecimal(
                        Money::toMinor((float) $totals['imputado_ars']) + array_sum(array_map(static fn ($imputation): int => $imputation->amountMinor(), $imputations)),
                    ),
                ],
            );
        });
    }
}
