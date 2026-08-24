<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\ListWorkOrdersDashboard;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderDashboardReadModel;
use DomainException;

final class WorkOrders extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $filters = [
            'q' => trim((string) $this->request->getGet('q')),
            'status' => trim((string) $this->request->getGet('estado')),
            'branch_id' => $this->nullableInt($this->request->getGet('sucursal_id')),
            'owner_id' => $this->nullableInt($this->request->getGet('responsable_id')),
            'attention' => trim((string) $this->request->getGet('atencion')),
        ];
        $page = max(1, (int) ($this->request->getGet('page') ?: 1));
        $perPage = (int) ($this->request->getGet('per_page') ?: 25);
        $data = $this->dashboard()->execute($actor, $filters, $page, $perPage);

        return $this->renderApp(
            $actor,
            'work-orders',
            'work-orders-index',
            'Órdenes de trabajo',
            service('operationsPayload')->workOrders($data, $filters, [
                'editOrder' => $actor->hasPermission('ordenes.editar'),
                'closeOrder' => $actor->hasPermission('ordenes.cerrar'),
            ]),
        );
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->companyId() === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }
        return $actor;
    }

    private function dashboard(): ListWorkOrdersDashboard
    {
        return new ListWorkOrdersDashboard(new CodeIgniterWorkOrderDashboardReadModel(db_connect()));
    }
}
