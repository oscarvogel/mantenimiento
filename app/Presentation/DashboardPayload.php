<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Application\Identity\ActorContext;

final class DashboardPayload
{
    /** @param array<string,mixed> $operations */
    public function fromOperations(ActorContext $actor, array $operations): array
    {
        $canEquipment = $actor->hasPermission('equipos.ver');
        $canPlans = $actor->hasPermission('planes.ver');
        $canOrders = $actor->hasPermission('ordenes.editar');
        $equipmentUrl = $canEquipment ? base_url('mantenimiento/equipos') : '#';
        $plansUrl = $canPlans ? base_url('mantenimiento/planes') : '#';

        return [
            'metrics' => $operations['metrics'] ?? [],
            'upcomingMaintenance' => array_map(
                fn (array $item): array => $this->maintenanceItem($item, $canEquipment, $canPlans, $canOrders),
                $operations['upcomingMaintenance'] ?? [],
            ),
            'links' => [
                'equipment' => $equipmentUrl,
                'maintenance' => $plansUrl,
                'maintenanceDueSoon' => $canPlans ? $this->plansFilterUrl('PROXIMO') : '#',
                'maintenanceOverdue' => $canPlans ? $this->plansFilterUrl('VENCIDO') : '#',
            ],
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function maintenanceItem(array $item, bool $canEquipment, bool $canPlans, bool $canOrders): array
    {
        $equipmentId = (int) ($item['equipmentId'] ?? 0);
        $status = (string) ($item['status'] ?? 'SIN_DATOS');
        $detailUrl = $canEquipment && $equipmentId > 0
            ? base_url('mantenimiento/equipos/' . $equipmentId)
            : null;
        $planUrl = $canPlans && $equipmentId > 0
            ? $this->plansFilterUrl($status, $equipmentId)
            : null;
        $actionUrl = $planUrl ?? $detailUrl;
        $actionLabel = $planUrl !== null
            ? ($status === 'VENCIDO' ? ($canOrders ? 'Atender' : 'Ver vencido') : 'Ver plan')
            : ($detailUrl !== null ? 'Ver equipo' : null);

        return $item + [
            'detailUrl' => $detailUrl,
            'actionUrl' => $actionUrl,
            'actionLabel' => $actionLabel,
        ];
    }

    private function plansFilterUrl(string $status, ?int $equipmentId = null): string
    {
        $query = ['estado' => $status];
        if ($equipmentId !== null && $equipmentId > 0) {
            $query = ['equipo_id' => $equipmentId] + $query;
        }

        return base_url('mantenimiento/planes') . '?' . http_build_query($query);
    }
}
