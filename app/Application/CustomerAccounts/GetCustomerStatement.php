<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountReadModel;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use DomainException;

/** Estado de cuenta de un cliente: movimientos en ARS con saldo acumulado. */
final class GetCustomerStatement
{
    public function __construct(
        private readonly CustomerAccountReadModel $readModel,
        private readonly ExchangeRateCatalog $rates,
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

        return $statement + [
            'canFormalize' => $actor->hasPermission(CustomerAccountAccess::FORMALIZE),
            'currencies' => $this->rates->currencies(),
            'currentRates' => array_filter(array_combine(
                $this->rates->currencies(),
                array_map(fn (string $currency): ?float => $this->rates->currentRate($companyId, $currency), $this->rates->currencies()),
            ), static fn (?float $rate): bool => $rate !== null),
        ];
    }
}
