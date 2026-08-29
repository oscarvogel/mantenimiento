<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Assets\EquipmentListQuery;
use App\Application\Assets\ListEquipment;
use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\ListWorkOrdersDashboard;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderDashboardReadModel;
use App\Presentation\WorkOrdersPayload;
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
        $data['correctiveEquipments'] = $actor->hasPermission('ordenes.editar')
            ? $this->correctiveEquipmentOptions($actor)
            : [];

        return $this->renderApp(
            $actor,
            'work-orders',
            'work-orders-index',
            'Órdenes de trabajo',
            (new WorkOrdersPayload())->build($data, $filters, [
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

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Se recibió un número entero inválido.');
        }

        return (int) $value;
    }

    private function dashboard(): ListWorkOrdersDashboard
    {
        return new ListWorkOrdersDashboard(new CodeIgniterWorkOrderDashboardReadModel(db_connect()));
    }

    private function equipment(): ListEquipment
    {
        return service('equipmentList');
    }

    /** @return list<array<string,mixed>> */
    private function correctiveEquipmentOptions(ActorContext $actor): array
    {
        $page = $this->equipment()->execute($actor, new EquipmentListQuery(
            status: 'ACTIVO',
            page: 1,
            perPage: 100,
        ));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['codigo'],
            'plate' => $row['patente'] ?? null,
            'typeName' => (string) $row['tipo_nombre'],
            'branchName' => (string) $row['sucursal_nombre'],
            'controlsKm' => (int) ($row['controla_km'] ?? 0) === 1,
            'controlsHours' => (int) ($row['controla_horas'] ?? 0) === 1,
            'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'],
            'currentHours' => $row['horas_actuales'] ?? null,
        ], $page['items'] ?? []);
    }
}
