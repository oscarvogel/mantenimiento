<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\Port\AuditTrail;
use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use App\Application\CustomerAccounts\Port\CustomerAccountsUnitOfWork;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\CustomerReceipt;
use DomainException;

/**
 * Registra un recibo de cobranza, en borrador o confirmado en el mismo acto
 * (#536).
 *
 * Todo ocurre en una transacción: el número se reserva con la tabla de
 * numeración bloqueada, los cargos se bloquean para validar lo pendiente y los
 * asientos de los medios que se acreditan al confirmar se insertan con clave
 * idempotente. Si algo falla, no queda ni número consumido ni crédito parcial.
 */
final class CreateCustomerReceipt
{
    public function __construct(
        private readonly CustomerReceiptRepository $receipts,
        private readonly ExchangeRateCatalog $rates,
        private readonly CustomerAccountsUnitOfWork $unitOfWork,
        private readonly CustomerAccountsClock $clock,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(ActorContext $actor, CreateCustomerReceiptCommand $command): int
    {
        $companyId = CustomerReceiptAccess::register($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        if ($command->imputations !== []) {
            CustomerReceiptAccess::impute($actor);
        }
        if ($command->confirm) {
            CustomerReceiptAccess::confirm($actor);
        }
        $now = $this->clock->now();

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds, $now): int {
            if (! $this->receipts->customerExists($companyId, $command->customerId)) {
                throw new DomainException('El cliente no existe o no pertenece a la empresa.');
            }
            if (! $this->receipts->branchInScope($companyId, $command->branchId, $branchIds)) {
                throw new DomainException('La sucursal del recibo no existe o no está autorizada.');
            }

            $instruments = [];
            foreach ($command->instruments as $input) {
                $instruments[] = ReceiptInstrumentFactory::build($this->rates, $companyId, $input, $command->receptionDate, $now);
            }
            $imputations = ReceiptInstrumentFactory::imputations($command->imputations);
            $receipt = CustomerReceipt::draft(
                $companyId,
                $command->branchId,
                $command->customerId,
                $command->series,
                $command->issueDate,
                $command->receptionDate,
                $command->notes,
                $instruments,
                $imputations,
                $now,
            );

            // Un borrador puede reservar hasta el total; al confirmar se exige
            // que lo imputado esté respaldado por medios acreditados.
            ReceiptImputationGuard::validate(
                $this->receipts,
                $companyId,
                $command->customerId,
                $imputations,
                $command->confirm ? $receipt->creditableOnConfirmationMinor() : $receipt->totalMinor(),
            );

            $receiptId = $this->receipts->createDraft($companyId, $receipt, $actor->userId(), $now);
            CustomerAccountAudit::record(
                $this->audit,
                $actor,
                $companyId,
                $command->branchId,
                AuditAction::CLIENTE_RECIBO_REGISTRADO,
                'cliente_recibos',
                $receiptId,
                null,
                $receipt->snapshot(),
            );

            if ($command->confirm) {
                $number = $this->receipts->nextNumber($companyId, $command->branchId, $receipt->series());
                $receipt->confirm($number, $now, $actor->userId(), $receipt->creditableOnConfirmationMinor());
                $this->receipts->confirm($companyId, $receiptId, $number, $now, $actor->userId());
                CustomerAccountAudit::record(
                    $this->audit,
                    $actor,
                    $companyId,
                    $command->branchId,
                    AuditAction::CLIENTE_RECIBO_CONFIRMADO,
                    'cliente_recibos',
                    $receiptId,
                    null,
                    $receipt->snapshot(),
                );
            }

            return $receiptId;
        });
    }
}
