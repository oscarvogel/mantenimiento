<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Application\Platform\Port\FleetAvailabilityReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterFleetAvailabilityReadModel implements FleetAvailabilityReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function rows(int $companyId, ?array $branchIds, ?int $branchId): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->database->table('equipos e')
            ->select('e.id equipo_id, e.codigo, e.patente, s.nombre sucursal_nombre')
            ->select('o.id orden_id, o.numero, o.estado orden_estado, o.fecha_apertura, o.equipo_fuera_servicio, o.inicio_detencion, o.fin_detencion')
            ->select("COALESCE(NULLIF(o.diagnostico, ''), ts.nombre) motivo", false)
            ->join('sucursales s', 's.id = e.sucursal_id')
            // Misma definición de OT abierta que EquipmentWorkStatus::hasOpenOrders. Constantes, sin datos del usuario.
            ->join('ordenes_trabajo o', "o.equipo_id = e.id AND o.empresa_id = e.empresa_id AND o.estado NOT IN ('FINALIZADA', 'CANCELADA')", 'left', false)
            ->join('tipos_servicio ts', 'ts.id = o.tipo_servicio_id', 'left')
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->orderBy('e.id')
            ->orderBy('o.fecha_apertura');
        if ($branchIds !== null) {
            $builder->whereIn('e.sucursal_id', $branchIds);
        }
        if ($branchId !== null) {
            $builder->where('e.sucursal_id', $branchId);
        }

        return array_map(static fn (array $row): array => [
            'equipmentId' => (int) $row['equipo_id'],
            'code' => (string) $row['codigo'],
            'plate' => $row['patente'] === null || $row['patente'] === '' ? null : (string) $row['patente'],
            'branchName' => (string) $row['sucursal_nombre'],
            'orderId' => $row['orden_id'] === null ? null : (int) $row['orden_id'],
            'orderNumber' => $row['numero'] === null ? null : (string) $row['numero'],
            'orderStatus' => $row['orden_estado'] === null ? null : (string) $row['orden_estado'],
            'openedAt' => $row['fecha_apertura'] === null ? null : (string) $row['fecha_apertura'],
            'outOfService' => (int) ($row['equipo_fuera_servicio'] ?? 0) === 1,
            'stopStartedAt' => $row['inicio_detencion'] === null ? null : (string) $row['inicio_detencion'],
            'stopEndedAt' => $row['fin_detencion'] === null ? null : (string) $row['fin_detencion'],
            'reason' => $row['motivo'] === null ? null : (string) $row['motivo'],
        ], $builder->get()->getResultArray());
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre');
        if ($branchIds !== null) {
            $builder->whereIn('id', $branchIds);
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nombre']],
            $builder->get()->getResultArray(),
        );
    }
}
