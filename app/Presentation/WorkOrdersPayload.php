<?php

declare(strict_types=1);

namespace App\Presentation;

final class WorkOrdersPayload
{
    /** @param array<string,mixed> $source @param array<string,mixed> $filters @param array<string,bool> $can */
    public function build(array $source, array $filters, array $can): array
    {
        $base = base_url('mantenimiento/ordenes');
        $query = array_filter([
            'q' => $filters['q'] ?? '',
            'estado' => $filters['status'] ?? '',
            'sucursal_id' => $filters['branch_id'] ?? null,
            'responsable_id' => $filters['owner_id'] ?? null,
            'atencion' => $filters['attention'] ?? '',
            'per_page' => $source['perPage'] ?? 25,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $items = array_map(static function (array $row): array {
            $id = (int) $row['id'];
            return [
                'id' => $id,
                'number' => (string) $row['numero'],
                'origin' => (string) $row['origen'],
                'priority' => (string) $row['prioridad'],
                'status' => (string) $row['estado'],
                'equipmentCode' => (string) $row['equipo_codigo'],
                'plate' => $row['equipo_patente'],
                'branchName' => (string) $row['sucursal_nombre'],
                'serviceName' => $row['servicio_nombre'] ?: 'Sin servicio',
                'ownerName' => $row['responsable_nombre'] ?: 'Sin asignar',
                'openedAt' => (string) $row['fecha_apertura'],
                'startedAt' => $row['fecha_inicio'] ?: null,
                'finishedAt' => $row['fecha_finalizacion'] ?: null,
                'ageDays' => (int) $row['antiguedad_dias'],
                'delayed' => (bool) $row['demorada'],
                'entryKm' => $row['km_ingreso'] === null ? null : (int) $row['km_ingreso'],
                'entryHours' => $row['horas_ingreso'],
                'currentKm' => $row['equipo_km_actual'] === null ? null : (int) $row['equipo_km_actual'],
                'currentHours' => $row['equipo_horas_actuales'],
                'diagnosis' => $row['diagnostico'] ?: null,
                'notes' => $row['observaciones'] ?: null,
                'costs' => [
                    'labor' => (float) ($row['costo_mano_obra'] ?? 0),
                    'parts' => (float) ($row['costo_repuestos'] ?? 0),
                    'other' => (float) ($row['otros_costos'] ?? 0),
                    'total' => (float) ($row['costo_total'] ?? 0),
                ],
                'tasks' => array_map(static fn (array $task): array => [
                    'id' => (int) $task['id'],
                    'description' => (string) $task['descripcion_solicitada'],
                    'status' => (string) $task['estado'],
                    'workPerformed' => $task['trabajo_realizado'] ?: null,
                ], $row['tareas'] ?? []),
                'routes' => [
                    'print' => base_url('mantenimiento/ordenes/' . $id . '/imprimir'),
                    'start' => base_url('mantenimiento/ordenes/' . $id . '/iniciar'),
                    'waitParts' => base_url('mantenimiento/ordenes/' . $id . '/esperar-repuestos'),
                    'resume' => base_url('mantenimiento/ordenes/' . $id . '/reanudar'),
                    'close' => base_url('mantenimiento/ordenes/' . $id . '/cerrar'),
                ],
            ];
        }, $source['items'] ?? []);

        return [
            'routes' => [
                'index' => $base,
                'maintenance' => base_url('mantenimiento'),
                'registerCorrective' => base_url('mantenimiento') . '?ot_correctiva=1',
            ],
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
                'branchId' => $filters['branch_id'] ?? '',
                'ownerId' => $filters['owner_id'] ?? '',
                'attention' => $filters['attention'] ?? '',
            ],
            'can' => $can,
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            'kpis' => $source['kpis'] ?? [],
            'delayDays' => (int) ($source['delayDays'] ?? 0),
            'branches' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nombre']], $source['branches'] ?? []),
            'owners' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nombre']], $source['owners'] ?? []),
            'orders' => $items,
            'pagination' => [
                'page' => (int) ($source['page'] ?? 1),
                'totalPages' => (int) ($source['totalPages'] ?? 1),
                'total' => (int) ($source['total'] ?? 0),
                'perPage' => (int) ($source['perPage'] ?? 25),
                'previousUrl' => ((int) ($source['page'] ?? 1)) > 1 ? $base . '?' . http_build_query([...$query, 'page' => (int) $source['page'] - 1]) : null,
                'nextUrl' => ((int) ($source['page'] ?? 1)) < ((int) ($source['totalPages'] ?? 1)) ? $base . '?' . http_build_query([...$query, 'page' => (int) $source['page'] + 1]) : null,
            ],
        ];
    }
}
