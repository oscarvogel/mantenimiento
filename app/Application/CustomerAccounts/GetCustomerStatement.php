<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountReadModel;
use App\Application\CustomerAccounts\Port\CustomerReceiptReadModel;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Estado de cuenta de un cliente: movimientos en ARS con saldo acumulado y,
 * desde #536, el resumen de cobranzas, documentos pendientes y anticipos.
 */
final class GetCustomerStatement
{
    public function __construct(
        private readonly CustomerAccountReadModel $readModel,
        private readonly ExchangeRateCatalog $rates,
        private readonly CustomerReceiptReadModel $receipts,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $customerId): array
    {
        $companyId = CustomerAccountAccess::view($actor);
        $statement = $this->readModel->customerStatement($companyId, $customerId);
        if ($statement === null) {
            throw new DomainException('El cliente no existe o no pertenece a la empresa.');
        }
        $collection = $this->receipts->customerCollection($companyId, $customerId);

        return $statement + $collection + [
            'canFormalize' => $actor->hasPermission(CustomerAccountAccess::FORMALIZE),
            'canRegisterReceipt' => $actor->hasPermission(CustomerReceiptAccess::REGISTER),
            'canConfirmReceipt' => $actor->hasPermission(CustomerReceiptAccess::CONFIRM),
            'canCancelReceipt' => $actor->hasPermission(CustomerReceiptAccess::CANCEL),
            'canImpute' => $actor->hasPermission(CustomerReceiptAccess::IMPUTE),
            'canPrintReceipt' => $actor->hasPermission(CustomerReceiptAccess::PRINT),
            'currencies' => $this->rates->currencies(),
            'currentRates' => array_filter(array_combine(
                $this->rates->currencies(),
                array_map(fn (string $currency): ?float => $this->rates->currentRate($companyId, $currency), $this->rates->currencies()),
            ), static fn (?float $rate): bool => $rate !== null),
        ];
    }
}
