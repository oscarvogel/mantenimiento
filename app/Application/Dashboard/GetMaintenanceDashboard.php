<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Dashboard\Port\DashboardDuePlans;
use App\Application\Dashboard\Port\DashboardOverview;
use App\Application\Dashboard\Port\DashboardClock;
use App\Application\Identity\ActorContext;
use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use DateTimeImmutable;
use DomainException;

final class GetMaintenanceDashboard
{
    private const STALE_READING_DAYS = 7;

    public function __construct(
        private readonly DashboardOverview $overview,
        private readonly DashboardDuePlans $duePlans,
        private readonly DashboardClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El tablero operativo requiere una cuenta perteneciente a una empresa.');
        }

        $overview = $this->overview->fetch($actor);
        $equipment = $actor->hasPermission('equipos.ver') ? $overview['equipments'] : [];
        $orders = $actor->hasPermission('ordenes.ver') ? $overview['orders'] : [];
        $readings = $actor->hasPermission('lecturas.ver') || $actor->hasPermission('lecturas.cargar')
            ? ($overview['readings'] ?? [])
            : [];
        $evaluations = $actor->hasPermission('planes.ver')
            ? $this->duePlans->fetch($actor, $actor->companyId())
            : [];

        $planRows = [];
        foreach ($overview['plans'] as $row) {
            $planRows[(int) $row['id']] = $row;
        }

        $branchNames = [];
        foreach ($overview['branches'] as $branch) {
            $branchNames[(int) $branch['id']] = (string) $branch['nombre'];
        }

        $statusCounts = array_fill_keys(array_column(EstadoPlan::cases(), 'value'), 0);
        $maintenance = [];
        $equipmentIdsWithPlans = [];
        foreach ($evaluations as $result) {
            $plan = $result['plan'];
            $evaluation = $result['evaluation'];
            $state = $evaluation->estado()->value;
            $statusCounts[$state]++;
            $equipmentIdsWithPlans[$plan->equipoId()] = true;
            $row = $planRows[(int) $plan->id()] ?? null;
            if ($row === null) {
                continue;
            }
            $maintenance[] = [
                'planId' => (int) $plan->id(),
                'equipmentId' => $plan->equipoId(),
                'equipmentCode' => (string) $row['equipo_codigo'],
                'serviceName' => (string) $row['servicio_nombre'],
                'branchName' => (string) ($row['sucursal_nombre'] ?? $branchNames[(int) $row['sucursal_id']] ?? ''),
                'status' => $state,
                'statusLabel' => $this->statusLabel($evaluation->estado()),
                'remaining' => $this->remainingText($row, $evaluation->criteriosDisparadores()),
                'priority' => $plan->prioridad(),
            ];
        }
        usort($maintenance, static fn (array $left, array $right): int => [
            'VENCIDO' => 0, 'PROXIMO' => 1, 'SIN_DATOS' => 2, 'AL_DIA' => 3,
        ][$left['status']] <=> [
            'VENCIDO' => 0, 'PROXIMO' => 1, 'SIN_DATOS' => 2, 'AL_DIA' => 3,
        ][$right['status']]);

        $activeEquipment = count(array_filter($equipment, static fn (array $item): bool => $item['estado'] === 'ACTIVO'));
        $equipmentWithoutPlans = count(array_filter(
            $equipment,
            static fn (array $item): bool => $item['estado'] === 'ACTIVO'
                && ! isset($equipmentIdsWithPlans[(int) $item['id']]),
        ));
        $openOrders = count(array_filter($orders, static fn (array $item): bool => ! in_array($item['estado'], ['FINALIZADA', 'CANCELADA'], true)));
        $openCorrectiveOrders = count(array_filter($orders, static fn (array $item): bool => ($item['origen'] ?? '') === 'CORRECTIVO'
            && ! in_array($item['estado'], ['FINALIZADA', 'CANCELADA'], true)));

        $readingControl = $this->readingControl($equipment, $readings, $branchNames);
        $preventiveTotal = $statusCounts[EstadoPlan::AL_DIA->value]
            + $statusCounts[EstadoPlan::PROXIMO->value]
            + $statusCounts[EstadoPlan::VENCIDO->value];
        $preventiveCompliance = $preventiveTotal > 0
            ? (int) round(($statusCounts[EstadoPlan::AL_DIA->value] / $preventiveTotal) * 100)
            : null;

        return [
            'company' => $overview['company'],
            'branches' => $overview['branches'],
            'metrics' => [
                'equipmentTotal' => count($equipment),
                'equipmentActive' => $activeEquipment,
                'equipmentWithoutPlans' => $equipmentWithoutPlans,
                'plansConfigured' => count($planRows),
                'maintenanceDueSoon' => $statusCounts[EstadoPlan::PROXIMO->value],
                'maintenanceOverdue' => $statusCounts[EstadoPlan::VENCIDO->value],
                'maintenanceMissingData' => $statusCounts[EstadoPlan::SIN_DATOS->value],
                'maintenanceScheduled' => $statusCounts[EstadoPlan::AL_DIA->value],
                'preventiveCompliance' => $preventiveCompliance,
                'openOrders' => $openOrders,
                'openCorrectiveOrders' => $openCorrectiveOrders,
                'equipmentWithoutReading' => $readingControl['withoutReading'],
                'equipmentWithStaleReading' => $readingControl['staleReading'],
                'staleReadingDays' => self::STALE_READING_DAYS,
            ],
            'readingAttention' => $readingControl['attention'],
            'upcomingMaintenance' => array_slice($maintenance, 0, 8),
        ];
    }

    /**
     * @param list<array<string,mixed>> $equipment
     * @param list<array<string,mixed>> $readings
     * @param array<int,string> $branchNames
     * @return array{withoutReading:int,staleReading:int,attention:list<array<string,mixed>>}
     */
    private function readingControl(array $equipment, array $readings, array $branchNames): array
    {
        $latestByEquipment = [];
        foreach ($readings as $reading) {
            $equipmentId = (int) ($reading['equipo_id'] ?? 0);
            if ($equipmentId > 0 && ! isset($latestByEquipment[$equipmentId])) {
                $latestByEquipment[$equipmentId] = $reading;
            }
        }

        $today = $this->clock->today();
        $withoutReading = 0;
        $staleReading = 0;
        $attention = [];

        foreach ($equipment as $item) {
            if (($item['estado'] ?? '') !== 'ACTIVO') {
                continue;
            }
            $equipmentId = (int) ($item['id'] ?? 0);
            if ($equipmentId <= 0) {
                continue;
            }
            $equipmentLabel = (string) ($item['codigo'] ?? $item['patente'] ?? ('Equipo #' . $equipmentId));
            $branchId = (int) ($item['sucursal_id'] ?? 0);
            $branchName = (string) ($item['sucursal_nombre'] ?? ($branchId > 0 ? ($branchNames[$branchId] ?? '') : ''));
            $latest = $latestByEquipment[$equipmentId] ?? null;
            if ($latest === null || empty($latest['fecha_lectura'])) {
                $withoutReading++;
                $attention[] = [
                    'equipmentId' => $equipmentId,
                    'equipment' => $equipmentLabel,
                    'branchName' => $branchName,
                    'status' => 'SIN_LECTURA',
                    'statusLabel' => 'Sin lectura',
                    'lastReadingDate' => null,
                    'daysSinceReading' => null,
                    'detail' => 'Nunca registró km/horas',
                ];
                continue;
            }

            $lastDate = new DateTimeImmutable((string) $latest['fecha_lectura']);
            $days = max(0, (int) $lastDate->diff($today)->format('%r%a'));
            if ($days <= self::STALE_READING_DAYS) {
                continue;
            }

            $staleReading++;
            $attention[] = [
                'equipmentId' => $equipmentId,
                'equipment' => $equipmentLabel,
                'branchName' => $branchName,
                'status' => 'DESACTUALIZADA',
                'statusLabel' => 'Lectura antigua',
                'lastReadingDate' => $lastDate->format('Y-m-d'),
                'daysSinceReading' => $days,
                'detail' => "Hace {$days} días que no registra km/horas",
            ];
        }

        usort($attention, static function (array $left, array $right): int {
            $leftDays = $left['daysSinceReading'] ?? PHP_INT_MAX;
            $rightDays = $right['daysSinceReading'] ?? PHP_INT_MAX;

            return $rightDays <=> $leftDays;
        });

        return [
            'withoutReading' => $withoutReading,
            'staleReading' => $staleReading,
            'attention' => array_slice($attention, 0, 8),
        ];
    }

    /** @param list<string> $criteria */
    private function remainingText(array $row, array $criteria): string
    {
        $criterion = $criteria[0] ?? null;
        if (($criterion === CriterioPlan::KILOMETRAJE->value || $criterion === null)
            && $row['proximo_km'] !== null && $row['km_actual'] !== null) {
            return $this->distance((float) $row['proximo_km'] - (float) $row['km_actual'], 'km');
        }
        if (($criterion === CriterioPlan::HOROMETRO->value || $criterion === null)
            && $row['proximas_horas'] !== null && $row['horas_actuales'] !== null) {
            return $this->distance((float) $row['proximas_horas'] - (float) $row['horas_actuales'], 'h');
        }
        if ($row['proxima_fecha'] !== null) {
            $today = $this->clock->today();
            $target = new DateTimeImmutable((string) $row['proxima_fecha']);
            $days = (int) $today->diff($target)->format('%r%a');

            return $days < 0 ? 'Vencido por ' . abs($days) . ' días' : ($days === 0 ? 'Vence hoy' : 'Faltan ' . $days . ' días');
        }

        return 'Sin datos suficientes';
    }

    private function distance(float $distance, string $unit): string
    {
        $formatted = number_format(abs($distance), $unit === 'h' ? 1 : 0, ',', '.');

        return $distance < 0 ? "Vencido por {$formatted} {$unit}" : ($distance === 0.0 ? 'Vence ahora' : "Faltan {$formatted} {$unit}");
    }

    private function statusLabel(EstadoPlan $status): string
    {
        return match ($status) {
            EstadoPlan::AL_DIA => 'Al día',
            EstadoPlan::PROXIMO => 'Próximo',
            EstadoPlan::VENCIDO => 'Vencido',
            EstadoPlan::SIN_DATOS => 'Sin datos',
        };
    }
}
