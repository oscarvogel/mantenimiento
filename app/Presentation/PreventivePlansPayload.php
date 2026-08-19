<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Application\PreventiveMaintenance\PreventivePlanPage;

final class PreventivePlansPayload
{
    /** @param array<string,mixed> $filters */
    public function fromPage(PreventivePlanPage $page, array $filters, bool $canEdit, bool $canViewEquipment, array $primaryPhotos = []): array
    {
        $base = base_url('mantenimiento/planes');
        $query = array_filter([
            'q' => $filters['q'] ?? null,
            'sucursal_id' => $filters['branch_id'] ?? null,
            'equipo_id' => $filters['equipment_id'] ?? null,
            'estado' => $filters['state'] ?? null,
            'por_pagina' => $page->perPage,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'canEdit' => $canEdit,
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
                    'criteria' => [
                        'kilometers' => $this->criterion($row['interval_km'], $row['warning_km'], $row['base_km'], $row['next_km'], $row['current_km']),
                        'hours' => $this->criterion($row['interval_hours'], $row['warning_hours'], $row['base_hours'], $row['next_hours'], $row['current_hours']),
                        'date' => $this->criterion($row['interval_days'], $row['warning_days'], $row['base_date'], $row['next_date'], $row['current_date']),
                    ],
                    'notes' => $row['notes'],
                ], $page->items),
                'pagination' => $this->pagination($page, $base, $query),
            ],
        ];
    }

    /** @return array{interval:mixed,warning:mixed,base:mixed,next:mixed,current:mixed}|null */
    private function criterion(mixed $interval, mixed $warning, mixed $base, mixed $next, mixed $current): ?array
    {
        return $interval === null ? null : compact('interval', 'warning', 'base', 'next', 'current');
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
