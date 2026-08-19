<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Application\PreventiveMaintenance\PreventivePlanPage;
use DateTimeImmutable;

final class PreventivePlansPayload
{
    /** @param array<string,mixed> $filters */
    public function fromPage(PreventivePlanPage $page, array $filters, bool $canEdit, bool $canViewEquipment, array $primaryPhotos = [], bool $canManageOrders = false): array
    {
        $base = base_url('mantenimiento/planes');
        $query = array_filter([
            'q' => $filters['q'] ?? null,
            'sucursal_id' => $filters['branch_id'] ?? null,
            'equipo_id' => $filters['equipment_id'] ?? null,
            'estado' => $filters['state'] ?? null,
            'por_pagina' => $page->perPage,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $planIds = array_values(array_filter(array_map(static fn (array $row): int => (int) $row['id'], $page->items)));
        $openOrdersByPlan = [];
        $database = db_connect();
        if ($planIds !== [] && $database->tableExists('ordenes_trabajo')) {
            $rows = $database->table('ordenes_trabajo')
                ->select('id, numero, plan_id, estado')
                ->whereIn('plan_id', $planIds)
                ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
                ->orderBy('id', 'DESC')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $planId = (int) $row['plan_id'];
                if (! isset($openOrdersByPlan[$planId])) {
                    $openOrdersByPlan[$planId] = [
                        'id' => (int) $row['id'],
                        'number' => (string) $row['numero'],
                        'status' => (string) $row['estado'],
                        'printUrl' => base_url('mantenimiento/ordenes/' . $row['id'] . '/imprimir'),
                    ];
                }
            }
        }

        return [
            'canEdit' => $canEdit,
            'canManageOrders' => $canManageOrders,
            'routes' => [
                'index' => $base,
                'create' => $base,
                'equipmentIndex' => base_url('mantenimiento/equipos'),
            ],
            'filters' => [
                'q' => (string) ($filters['q'] ?? ''),
                'branchId' => $filters['branch_id'] ?? '',
                'equipmentId' => $filters['equipment_id'] ?? '',
                'state' => (string) ($filters['state'] ?? ''),
                'perPage' => $page->perPage,
            ],
            'wizardEquipmentId' => $filters['equipment_id'] ?? '',
            'old' => $this->old(['equipo_id', 'tipo_servicio_id', 'base_km', 'base_horas', 'base_fecha', 'observaciones']),
            'catalogs' => [
                'equipment' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['codigo'],
                    'plate' => $row['patente'],
                    'branchId' => (int) $row['sucursal_id'],
                    'typeId' => (int) $row['tipo_equipo_id'],
                    'branchCode' => (string) $row['sucursal_codigo'],
                    'branchName' => (string) $row['sucursal_nombre'],
                    'typeName' => (string) $row['tipo_nombre'],
                    'brandName' => ($row['marca_nombre'] ?? null) === null ? null : (string) $row['marca_nombre'],
                    'modelName' => ($row['modelo_nombre'] ?? null) === null ? null : (string) $row['modelo_nombre'],
                    'controlsKm' => (int) $row['controla_km'] === 1,
                    'controlsHours' => (int) $row['controla_horas'] === 1,
                    'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'],
                    'currentHours' => $row['horas_actuales'],
                    'assignedServiceTypeIds' => array_values(array_map('intval', $row['assigned_service_type_ids'] ?? [])),
                    'photoUrl' => isset($primaryPhotos[(int) $row['id']]) ? base_url('mantenimiento/equipos/' . $row['id'] . '/foto-principal?miniatura=1') : null,
                ], $page->equipment),
                'serviceTypes' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['codigo'],
                    'name' => (string) $row['nombre'],
                    'description' => $row['descripcion'] === null ? null : (string) $row['descripcion'],
                    'category' => $row['categoria'] === null ? null : (string) $row['categoria'],
                    'intervalKm' => $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
                    'intervalHours' => $row['intervalo_horas'] === null ? null : (string) $row['intervalo_horas'],
                    'intervalDays' => $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
                    'warningKm' => $row['anticipacion_km'] === null ? null : (int) $row['anticipacion_km'],
                    'warningHours' => $row['anticipacion_horas'] === null ? null : (string) $row['anticipacion_horas'],
                    'warningDays' => $row['anticipacion_dias'] === null ? null : (int) $row['anticipacion_dias'],
                    'priority' => (string) ($row['prioridad'] ?: 'MEDIA'),
                ], $page->serviceTypes),
                'branches' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'], 'code' => (string) $row['codigo'], 'name' => (string) $row['nombre'],
                ], $page->branches),
                'templateDefaults' => [],
            ],
            'plans' => [
                'total' => $page->total,
                'items' => array_map(fn (array $row): array => [
                    'id' => $row['id'],
                    'equipment' => [
                        'id' => $row['equipment_id'], 'code' => $row['equipment_code'], 'plate' => $row['equipment_plate'],
                        'typeName' => $row['equipment_type_name'],
                        'detailUrl' => $canViewEquipment ? base_url('mantenimiento/equipos/' . $row['equipment_id']) : null,
                        'photoUrl' => isset($primaryPhotos[(int) $row['equipment_id']]) ? base_url('mantenimiento/equipos/' . $row['equipment_id'] . '/foto-principal?miniatura=1') : null,
                    ],
                    'branch' => ['id' => $row['branch_id'], 'code' => $row['branch_code'], 'name' => $row['branch_name']],
                    'serviceName' => $row['service_name'], 'state' => $row['state'], 'priority' => $row['priority'],
                    'editUrl' => $canEdit ? base_url('mantenimiento/planes/' . $row['id'] . '/editar') : null,
                    'openOrder' => $openOrdersByPlan[(int) $row['id']] ?? null,
                    'generateOrderUrl' => $canManageOrders
                        && ! isset($openOrdersByPlan[(int) $row['id']])
                        && in_array($row['state'], ['PROXIMO', 'VENCIDO'], true)
                            ? base_url('mantenimiento/planes/' . $row['id'] . '/orden')
                            : null,
                    'criteria' => [
                        'kilometers' => $this->criterion($row['interval_km'], $row['warning_km'], $row['base_km'], $row['next_km'], $row['current_km'], 'number'),
                        'hours' => $this->criterion($row['interval_hours'], $row['warning_hours'], $row['base_hours'], $row['next_hours'], $row['current_hours'], 'number'),
                        'date' => $this->criterion($row['interval_days'], $row['warning_days'], $row['base_date'], $row['next_date'], $row['current_date'], 'date'),
                    ],
                    'notes' => $row['notes'],
                ], $page->items),
                'pagination' => $this->pagination($page, $base, $query),
            ],
        ];
    }

    /** @return array{interval:mixed,warning:mixed,base:mixed,next:mixed,current:mixed,difference:int|float|null}|null */
    private function criterion(mixed $interval, mixed $warning, mixed $base, mixed $next, mixed $current, string $kind): ?array
    {
        if ($interval === null) return null;

        $difference = null;
        if ($next !== null && $current !== null) {
            if ($kind === 'date') {
                $nextDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $next);
                $currentDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $current);
                if ($nextDate !== false && $currentDate !== false) {
                    $difference = (int) $currentDate->diff($nextDate)->format('%r%a');
                }
            } else {
                $difference = (float) $next - (float) $current;
                if (floor($difference) === $difference) $difference = (int) $difference;
            }
        }

        return compact('interval', 'warning', 'base', 'next', 'current', 'difference');
    }

    /** @param list<string> $fields @return array<string,mixed> */
    private function old(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) $result[$field] = old($field) ?? '';
        return $result;
    }

    /** @param array<string,mixed> $query */
    private function pagination(PreventivePlanPage $page, string $base, array $query): array
    {
        $url = static fn (int $target): string => $base . '?' . http_build_query($query + ['page' => $target]);

        return [
            'page' => $page->page,
            'totalPages' => $page->totalPages(),
            'total' => $page->total,
            'perPage' => $page->perPage,
            'perPageOptions' => [5, 10, 25],
            'perPageKey' => 'por_pagina',
            'pageKey' => 'page',
            'previousUrl' => $page->page > 1 ? $url($page->page - 1) : null,
            'nextUrl' => $page->page < $page->totalPages() ? $url($page->page + 1) : null,
        ];
    }
}
