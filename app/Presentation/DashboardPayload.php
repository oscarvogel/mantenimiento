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
        $canEditEquipment = $actor->hasPermission('equipos.editar');
        $canPlans = $actor->hasPermission('planes.ver');
        $canEditPlans = $actor->hasPermission('planes.editar');
        $canLoadReadings = $actor->hasPermission('lecturas.cargar');
        $canOrders = $actor->hasPermission('ordenes.editar');
        $equipmentUrl = $canEquipment ? base_url('mantenimiento/equipos') : '#';
        $plansUrl = $canPlans ? base_url('mantenimiento/planes') : '#';
        $servicesUrl = $canPlans ? base_url('mantenimiento/servicios') : '#';
        $managerial = in_array('Administrador', $actor->roles(), true);

        return [
            'view' => $managerial ? 'managerial' : 'operational',
            'metrics' => $operations['metrics'] ?? [],
            'readingAttention' => array_map(
                static fn (array $item): array => $item + [
                    'detailUrl' => $canEquipment && (int) ($item['equipmentId'] ?? 0) > 0
                        ? base_url('mantenimiento/equipos/' . (int) $item['equipmentId'])
                        : null,
                ],
                $operations['readingAttention'] ?? [],
            ),
            'upcomingMaintenance' => array_map(
                fn (array $item): array => $this->maintenanceItem($item, $canEquipment, $canPlans, $canOrders),
                $operations['upcomingMaintenance'] ?? [],
            ),
            'links' => [
                'equipment' => $equipmentUrl,
                'equipmentCreate' => $canEditEquipment ? $equipmentUrl . '#nuevo-equipo' : '#',
                'maintenance' => $canPlans ? base_url('mantenimiento') : '#',
                'services' => $servicesUrl,
                'assignPlan' => $canEditPlans ? $plansUrl : '#',
                'registerMaintenance' => $canEquipment ? $equipmentUrl : '#',
                'quickReadings' => $canLoadReadings ? base_url('mantenimiento/lecturas/rapidas') : '#',
                'orders' => $canOrders ? base_url('mantenimiento') : '#',
                'correctiveOrder' => $canOrders ? base_url('mantenimiento?ot_correctiva=1') : '#',
                // Alias temporal para consumidores viejos. Ya no apunta a Biblioteca.
                'library' => $servicesUrl,
                'maintenanceDueSoon' => $canPlans ? $this->plansFilterUrl('PROXIMO') : '#',
                'maintenanceOverdue' => $canPlans ? $this->plansFilterUrl('VENCIDO') : '#',
                'maintenanceMissingData' => $canPlans ? $this->plansFilterUrl('SIN_DATOS') : '#',
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
            ? ($status === 'VENCIDO' ? ($canOrders ? 'Atender' : 'Ver vencido') : 'Ver mantenimiento')
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
