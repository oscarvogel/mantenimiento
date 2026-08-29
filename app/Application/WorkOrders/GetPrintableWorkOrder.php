<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\WorkOrderPrintReadModel;
use DomainException;

final readonly class GetPrintableWorkOrder
{
    public function __construct(private WorkOrderPrintReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $orderId): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La impresión de una orden requiere una cuenta perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('ordenes.ver') && ! $actor->hasPermission('ordenes.editar') && ! $actor->hasPermission('ordenes.mi_trabajo')) {
            throw new DomainException('No tenés permiso para consultar esta orden de trabajo.');
        }
        if ($orderId <= 0) {
            throw new DomainException('La orden de trabajo indicada no es válida.');
        }

        $order = $this->readModel->findScoped(
            $actor->companyId(),
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $orderId,
        );
        if ($order === null) {
            throw new DomainException('La orden de trabajo no existe o queda fuera de tu alcance.');
        }

        return $order;
    }
}
