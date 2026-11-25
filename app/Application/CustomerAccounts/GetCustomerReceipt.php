<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptReadModel;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use DomainException;

/** Detalle de un recibo, con acciones habilitadas según permisos (#536). */
final class GetCustomerReceipt
{
    public function __construct(
        private readonly CustomerReceiptReadModel $readModel,
        private readonly ExchangeRateCatalog $rates,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $receiptId): array
    {
        $companyId = CustomerReceiptAccess::view($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $detail = $this->readModel->detail($companyId, $branchIds, $receiptId);
        if ($detail === null) {
            throw new DomainException('El recibo no existe o no pertenece a la empresa.');
        }
        $collection = $this->readModel->customerCollection($companyId, (int) $detail['customer']['id']);
        $currencies = $this->rates->currencies();

        return $detail + [
            'pendingDocuments' => $collection['pendingDocuments'],
            'advances' => $collection['advances'],
            'pendingInstruments' => $collection['pendingInstruments'],
            'canConfirm' => $actor->hasPermission(CustomerReceiptAccess::CONFIRM),
            'canCancel' => $actor->hasPermission(CustomerReceiptAccess::CANCEL),
            'canImpute' => $actor->hasPermission(CustomerReceiptAccess::IMPUTE),
            'canPrint' => $actor->hasPermission(CustomerReceiptAccess::PRINT),
            'canRegister' => $actor->hasPermission(CustomerReceiptAccess::REGISTER),
            'currencies' => $currencies,
            'currentRates' => array_filter(array_combine(
                $currencies,
                array_map(fn (string $currency): ?float => $this->rates->currentRate($companyId, $currency), $currencies),
            ), static fn (?float $rate): bool => $rate !== null),
        ];
    }
}
