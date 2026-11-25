<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptReadModel;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use DomainException;

/** Prepara el formulario de un recibo nuevo para un cliente (#536). */
final class GetCustomerReceiptForm
{
    public function __construct(
        private readonly CustomerReceiptReadModel $readModel,
        private readonly ExchangeRateCatalog $rates,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $customerId): array
    {
        $companyId = CustomerReceiptAccess::register($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $data = $this->readModel->formData($companyId, $branchIds, $customerId);
        if ($data === null) {
            throw new DomainException('El cliente no existe o no pertenece a la empresa.');
        }
        $currencies = $this->rates->currencies();

        return $data + [
            'currencies' => $currencies,
            'currentRates' => array_filter(array_combine(
                $currencies,
                array_map(fn (string $currency): ?float => $this->rates->currentRate($companyId, $currency), $currencies),
            ), static fn (?float $rate): bool => $rate !== null),
            'canConfirm' => $actor->hasPermission(CustomerReceiptAccess::CONFIRM),
            'canImpute' => $actor->hasPermission(CustomerReceiptAccess::IMPUTE),
            'receipt' => null,
        ];
    }
}
