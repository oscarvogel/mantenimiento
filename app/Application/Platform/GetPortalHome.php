<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Application\Identity\ActorContext;
use App\Application\Platform\Port\PortalMaintenanceSummary;

/**
 * Arma el inicio del portal según lo que la persona hace cada día:
 * - gestión (planes u órdenes): indicadores y lo que requiere atención hoy;
 * - campo (taller, carga de km): accesos directos a su trabajo;
 * - el resto, y el Superadministrador: solo los módulos.
 * Los indicadores salen del tablero de Mantenimiento; acá no se calcula ninguno.
 * Los paths son relativos: el controlador les agrega la base.
 */
final readonly class GetPortalHome
{
    private const ATTENTION_PLANS = 5;
    private const ATTENTION_READINGS = 3;

    public function __construct(
        private GetPlatformModuleCatalog $catalog,
        private PortalMaintenanceSummary $maintenance,
    ) {
    }

    /** @return array{profile:string,modules:list<array<string,mixed>>,indicators:list<array<string,mixed>>,attention:list<array<string,mixed>>,shortcuts:list<array<string,mixed>>} */
    public function execute(ActorContext $actor): array
    {
        $home = [
            'profile' => 'modules',
            'modules' => $this->catalog->execute($actor),
            'indicators' => [],
            'attention' => [],
            'shortcuts' => [],
        ];
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            return $home;
        }

        if ($actor->hasPermission('planes.ver') || $actor->hasPermission('ordenes.ver')) {
            $summary = $this->maintenance->fetch($actor);

            return [
                ...$home,
                'profile' => 'management',
                'indicators' => $this->indicators($actor, $summary['metrics']),
                'attention' => $this->attention($actor, $summary),
            ];
        }

        $shortcuts = $this->shortcuts($actor);

        return $shortcuts === [] ? $home : [...$home, 'profile' => 'field', 'shortcuts' => $shortcuts];
    }

    /** @param array<string,mixed> $metrics @return list<array<string,mixed>> */
    private function indicators(ActorContext $actor, array $metrics): array
    {
        $indicators = [];
        if ($actor->hasPermission('planes.ver')) {
            $overdue = (int) ($metrics['maintenanceOverdue'] ?? 0);
            $dueSoon = (int) ($metrics['maintenanceDueSoon'] ?? 0);
            $indicators[] = [
                'key' => 'overdue',
                'label' => 'Mantenimientos vencidos',
                'value' => $overdue,
                'unit' => null,
                'tone' => $overdue > 0 ? 'danger' : 'success',
                'href' => 'mantenimiento/planes?estado=VENCIDO',
            ];
            $indicators[] = [
                'key' => 'due-soon',
                'label' => 'Próximos a vencer',
                'value' => $dueSoon,
                'unit' => null,
                'tone' => $dueSoon > 0 ? 'warning' : 'neutral',
                'href' => 'mantenimiento/planes?estado=PROXIMO',
            ];
        }
        if ($actor->hasPermission('ordenes.ver')) {
            $indicators[] = [
                'key' => 'open-orders',
                'label' => 'Órdenes abiertas',
                'value' => (int) ($metrics['openOrders'] ?? 0),
                'unit' => null,
                'tone' => 'neutral',
                'href' => 'mantenimiento/ordenes',
            ];
        }
        if ($actor->hasPermission('planes.ver')) {
            $compliance = $metrics['preventiveCompliance'] ?? null;
            $indicators[] = [
                'key' => 'compliance',
                'label' => 'Preventivo al día',
                // null = sin planes evaluables: la vista muestra "Sin datos suficientes", nunca 0 %.
                'value' => $compliance === null ? null : (int) $compliance,
                'unit' => '%',
                'tone' => 'neutral',
                'href' => 'mantenimiento/planes',
            ];
        }

        return $indicators;
    }

    /** @param array{upcomingMaintenance:list<array<string,mixed>>,readingAttention:list<array<string,mixed>>} $summary @return list<array<string,mixed>> */
    private function attention(ActorContext $actor, array $summary): array
    {
        $items = [];
        $plans = array_filter(
            $summary['upcomingMaintenance'],
            static fn (array $plan): bool => in_array($plan['status'] ?? '', ['VENCIDO', 'PROXIMO'], true),
        );
        foreach (array_slice(array_values($plans), 0, self::ATTENTION_PLANS) as $plan) {
            $items[] = [
                'title' => trim($plan['equipmentCode'] . ' · ' . $plan['serviceName']),
                'detail' => implode(' · ', array_filter([(string) $plan['remaining'], (string) $plan['branchName']])),
                'status' => $plan['status'],
                'statusLabel' => $plan['statusLabel'],
                'href' => 'mantenimiento/planes?estado=' . $plan['status'],
            ];
        }

        foreach (array_slice($summary['readingAttention'], 0, self::ATTENTION_READINGS) as $reading) {
            $items[] = [
                'title' => (string) $reading['equipment'],
                'detail' => implode(' · ', array_filter([(string) $reading['detail'], (string) $reading['branchName']])),
                'status' => $reading['status'],
                'statusLabel' => $reading['statusLabel'],
                'href' => $actor->hasPermission('equipos.ver')
                    ? 'mantenimiento/equipos/' . (int) $reading['equipmentId']
                    : null,
            ];
        }

        return $items;
    }

    /** Mismos accesos que la barra lateral de campo (`fieldShortcuts.js`), con los mismos permisos. @return list<array<string,mixed>> */
    private function shortcuts(ActorContext $actor): array
    {
        $shortcuts = [];
        if ($actor->hasPermission('ordenes.ver') || $actor->hasPermission('ordenes.mi_trabajo')) {
            $shortcuts[] = [
                'key' => 'my-work',
                'label' => 'Mi trabajo',
                'description' => 'Las órdenes que tenés asignadas, empezando por las que están en curso.',
                'href' => 'mantenimiento/ordenes?responsable_id=' . $actor->userId() . '&vista=mi-trabajo',
            ];
        }
        if ($actor->hasPermission('lecturas.cargar')) {
            $shortcuts[] = [
                'key' => 'readings',
                'label' => 'Registrar km/horas',
                'description' => 'Cargá el kilometraje u horómetro de un equipo.',
                'href' => 'mantenimiento/lecturas/rapidas',
            ];
            if ($actor->hasPermission('solicitudes.crear') || $actor->hasPermission('solicitudes.revisar')) {
                $shortcuts[] = [
                    'key' => 'report-failure',
                    'label' => 'Informar falla',
                    'description' => 'Elegí el equipo y contá qué le pasa.',
                    'href' => 'mantenimiento/lecturas/rapidas?accion=falla',
                ];
            }
        }

        return $shortcuts;
    }
}
