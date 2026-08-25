<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\WorkOrderDashboardReadModel;
use DomainException;

final readonly class ListWorkOrdersDashboard
{
    public function __construct(private WorkOrderDashboardReadModel $readModel)
    {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function execute(ActorContext $actor, array $filters, int $page = 1, int $perPage = 25): array
    {
        if (! $actor->hasPermission('ordenes.ver') && ! $actor->hasPermission('ordenes.mi_trabajo')) {
            throw new DomainException('No tenés permiso para consultar órdenes de trabajo.');
        }
        if ($actor->companyId() === null) {
            throw new DomainException('La consulta de órdenes requiere una empresa activa.');
        }

        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 25;

        return $this->readModel->search($actor, $filters, $page, $perPage);
    }
}
