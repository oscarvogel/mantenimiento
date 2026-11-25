<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptReadModel;
use App\Application\Identity\ActorContext;

/** Listado general de recibos con paginación real, búsqueda y filtros (#536). */
final class ListCustomerReceipts
{
    public function __construct(private readonly CustomerReceiptReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, ReceiptFilters $filters): array
    {
        $companyId = CustomerReceiptAccess::view($actor);
        $branchIds = CustomerReceiptAccess::branches($actor);
        $page = $this->readModel->list($companyId, $branchIds, $filters);

        return $page + [
            'filters' => $filters->toQuery(),
            'canRegister' => $actor->hasPermission(CustomerReceiptAccess::REGISTER),
            'canConfirm' => $actor->hasPermission(CustomerReceiptAccess::CONFIRM),
            'canCancel' => $actor->hasPermission(CustomerReceiptAccess::CANCEL),
            'canImpute' => $actor->hasPermission(CustomerReceiptAccess::IMPUTE),
            'canPrint' => $actor->hasPermission(CustomerReceiptAccess::PRINT),
            'statuses' => [
                ['value' => 'BORRADOR', 'label' => 'Borrador'],
                ['value' => 'CONFIRMADO', 'label' => 'Confirmado'],
                ['value' => 'ANULADO', 'label' => 'Anulado'],
            ],
            'media' => [
                ['value' => 'EFECTIVO', 'label' => 'Efectivo'],
                ['value' => 'TRANSFERENCIA', 'label' => 'Transferencia'],
                ['value' => 'CHEQUE', 'label' => 'Cheque físico'],
                ['value' => 'E_CHEQ', 'label' => 'eCheq'],
            ],
        ];
    }
}
