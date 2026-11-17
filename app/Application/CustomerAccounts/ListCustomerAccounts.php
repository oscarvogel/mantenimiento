<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountReadModel;
use App\Application\Identity\ActorContext;

/** Clientes con cuenta corriente y su saldo único en ARS. */
final class ListCustomerAccounts
{
    public function __construct(private readonly CustomerAccountReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, ?string $search = null): array
    {
        $companyId = CustomerAccountAccess::view($actor);
        $search = $search === null ? null : (trim($search) === '' ? null : mb_substr(trim($search), 0, 80));
        $rows = $this->readModel->customerBalances($companyId, $search);

        $totals = ['debe_ars' => 0.0, 'haber_ars' => 0.0, 'saldo_ars' => 0.0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($row[$key] ?? 0), 2);
            }
        }

        return [
            'search' => $search,
            'rows' => $rows,
            'totals' => $totals,
            'customers' => $this->readModel->customerOptions($companyId),
            'canFormalize' => $actor->hasPermission(CustomerAccountAccess::FORMALIZE),
        ];
    }
}
