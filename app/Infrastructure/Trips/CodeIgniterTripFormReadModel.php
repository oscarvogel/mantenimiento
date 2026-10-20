<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripFormReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripFormReadModel implements TripFormReadModel
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function options(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [
                'branches' => [],
                'tractors' => [],
                'trailers' => [],
                'drivers' => [],
                'customers' => [],
                'addresses' => [],
                'loadUnits' => [],
            ];
        }

        $branches = $this->db->table('sucursales')
            ->select('id, codigo, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');
        if ($branchIds !== null) {
            $branches->whereIn('id', $branchIds);
        }

        $equipment = $this->db->table('equipos e')
            ->select('e.id, e.sucursal_id, e.codigo, e.patente, t.nombre AS tipo_nombre')
            ->join('tipos_equipo t', 't.id = e.tipo_equipo_id')
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->orderBy('e.patente', 'ASC')
            ->orderBy('e.codigo', 'ASC');
        if ($branchIds !== null) {
            $equipment->whereIn('e.sucursal_id', $branchIds);
        }

        $tractors = [];
        $trailers = [];
        foreach ($equipment->get()->getResultArray() as $row) {
            $type = strtr(mb_strtolower((string) $row['tipo_nombre']), [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            ]);
            $item = [
                'id' => (int) $row['id'],
                'label' => $this->equipmentLabel($row),
                'type' => (string) $row['tipo_nombre'],
                'branchId' => (int) $row['sucursal_id'],
            ];
            if (preg_match('/acoplado|semirremolque|remolque|batea/', $type) === 1) {
                $trailers[] = $item;
            }
            if (preg_match('/camion|tractor|chasis|cabina|utilitario|pickup/', $type) === 1) {
                $tractors[] = $item;
            }
        }

        $drivers = $this->db->table('empleados')
            ->select('id, nombre, apellido, legajo')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('apellido', 'ASC')
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        $assignments = $this->db->table('employee_equipment_assignments a')
            ->select('a.empleado_id, a.equipo_id, e.codigo, e.patente')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id')
            ->where('a.empresa_id', $companyId)
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->get()->getResultArray();
        $assignedByDriver = [];
        foreach ($assignments as $assignment) {
            $plate = trim((string) ($assignment['patente'] ?? ''));
            $code = trim((string) ($assignment['codigo'] ?? ''));
            $assignedByDriver[(int) $assignment['empleado_id']] = [
                'equipmentId' => (int) $assignment['equipo_id'],
                'equipmentLabel' => $plate !== '' ? $plate : $code,
            ];
        }

        $customers = $this->db->table('clientes')
            ->select('id, nombre, identificacion_fiscal')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        $addresses = $this->db->table('viajes_domicilios_clientes d')
            ->select('d.id, d.cliente_id, d.alias, d.calle, d.localidad')
            ->where('d.empresa_id', $companyId)
            ->where('d.activo', 1)
            ->orderBy('d.alias', 'ASC')
            ->get()->getResultArray();

        $loadUnits = $this->db->table('viajes_unidades_carga')
            ->select('id, nombre, simbolo')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        return [
            'branches' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['codigo'],
                'name' => (string) $row['nombre'],
            ], $branches->get()->getResultArray()),
            'tractors' => $tractors,
            'trailers' => $trailers,
            'drivers' => array_map(static function (array $row) use ($assignedByDriver): array {
                $assignment = $assignedByDriver[(int) $row['id']] ?? null;

                return [
                    'id' => (int) $row['id'],
                    'label' => trim((string) $row['apellido'] . ', ' . (string) $row['nombre']),
                    'employeeNumber' => $row['legajo'] === null ? null : (string) $row['legajo'],
                    'equipmentId' => $assignment['equipmentId'] ?? null,
                    'assignedEquipmentLabel' => $assignment['equipmentLabel'] ?? null,
                ];
            }, $drivers),
            'customers' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['nombre'],
                'taxId' => $row['identificacion_fiscal'] === null ? null : (string) $row['identificacion_fiscal'],
            ], $customers),
            'addresses' => array_map(static function (array $row): array {
                $label = trim(implode(' · ', array_filter([
                    (string) $row['alias'],
                    (string) $row['calle'],
                    (string) $row['localidad'],
                ], static fn (string $value): bool => trim($value) !== '')));

                return [
                    'id' => (int) $row['id'],
                    'customerId' => (int) $row['cliente_id'],
                    'label' => $label,
                ];
            }, $addresses),
            'loadUnits' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => trim((string) $row['nombre'] . ' (' . (string) $row['simbolo'] . ')'),
                'symbol' => (string) $row['simbolo'],
            ], $loadUnits),
        ];
    }

    /** @param array<string,mixed> $row */
    private function equipmentLabel(array $row): string
    {
        $plate = trim((string) ($row['patente'] ?? ''));
        $code = trim((string) ($row['codigo'] ?? ''));

        return $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code;
    }
}
