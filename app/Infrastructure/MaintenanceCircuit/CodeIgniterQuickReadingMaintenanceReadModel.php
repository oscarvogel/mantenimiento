<?php

declare(strict_types=1);

namespace App\Infrastructure\MaintenanceCircuit;

use App\Application\MaintenanceCircuit\Port\QuickReadingMaintenanceReadModel;
use App\Domain\WorkOrders\WorkOrderStatus;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterQuickReadingMaintenanceReadModel implements QuickReadingMaintenanceReadModel
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function actions(int $companyId, ?array $branchIds, array $equipmentIds): array
    {
        $equipmentIds = array_values(array_unique(array_filter(array_map('intval', $equipmentIds), static fn (int $id): bool => $id > 0)));
        if ($equipmentIds === []) {
            return ['noticesByPlan' => [], 'ordersByPlan' => []];
        }

        $notices = $this->database->table('avisos_plan a')
            ->select('a.id, a.equipo_id, a.plan_id')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)
            ->where('a.estado_gestion', 'PENDIENTE')
            ->whereIn('a.equipo_id', $equipmentIds)
            ->orderBy('a.id', 'DESC');
        $this->scopeBranches($notices, 'e.sucursal_id', $branchIds);

        $noticesByPlan = [];
        foreach ($notices->get()->getResultArray() as $row) {
            $planId = (int) $row['plan_id'];
            if (isset($noticesByPlan[$planId])) {
                continue;
            }
            $noticesByPlan[$planId] = [
                'id' => (int) $row['id'],
                'equipmentId' => (int) $row['equipo_id'],
                'planId' => $planId,
            ];
        }

        $orders = $this->database->table('ordenes_trabajo o')
            ->select('o.id, o.numero, o.equipo_id, o.plan_id, o.estado')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->where('o.empresa_id', $companyId)
            ->whereIn('o.equipo_id', $equipmentIds)
            ->whereNotIn('o.estado', [WorkOrderStatus::COMPLETED->value, WorkOrderStatus::CANCELLED->value])
            ->where('o.plan_id IS NOT NULL', null, false)
            ->orderBy('o.id', 'DESC');
        $this->scopeBranches($orders, 'e.sucursal_id', $branchIds);

        $ordersByPlan = [];
        foreach ($orders->get()->getResultArray() as $row) {
            $planId = (int) $row['plan_id'];
            if ($planId <= 0 || isset($ordersByPlan[$planId])) {
                continue;
            }
            $ordersByPlan[$planId] = [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'equipmentId' => (int) $row['equipo_id'],
                'planId' => $planId,
                'status' => (string) $row['estado'],
            ];
        }

        return compact('noticesByPlan', 'ordersByPlan');
    }

    /** @param list<int>|null $branchIds */
    private function scopeBranches(BaseBuilder $builder, string $column, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }
        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);
            return;
        }
        $builder->whereIn($column, $branchIds);
    }
}
