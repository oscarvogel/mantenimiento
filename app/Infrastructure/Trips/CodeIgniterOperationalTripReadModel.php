<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Domain\Trips\Trip;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterOperationalTripReadModel implements OperationalTripReadModel
{
    private const STATUS_LABELS = [
        Trip::PLANIFICADO => 'Planificado',
        Trip::EN_CURSO => 'En curso',
        Trip::FINALIZADO => 'Finalizado',
        Trip::CANCELADO => 'Cancelado',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $builder = $this->db->table('sucursales')
            ->select('id, codigo code, nombre name')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');
        if ($branchIds !== null) {
            $builder->whereIn('id', $branchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ], $builder->get()->getResultArray());
    }

    public function list(int $companyId, ?array $branchIds, OperationalTripFilters $filters, int $page, int $perPage): array
    {
        if ($branchIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $builder = $this->db->table('viajes_operativos v')
            ->select('v.*')
            ->where('v.empresa_id', $companyId)
            ->orderBy('v.fecha_salida_planificada', 'DESC')
            ->orderBy('v.id', 'DESC');
        if ($branchIds !== null) {
            $builder->whereIn('v.sucursal_id', $branchIds);
        }
        if ($filters->branchId !== null) {
            $builder->where('v.sucursal_id', $filters->branchId);
        }
        if ($filters->status !== null) {
            $builder->where('v.estado', $filters->status);
        }
        if ($filters->from !== null) {
            $builder->where('v.fecha_salida_planificada >=', $filters->from . ' 00:00:00');
        }
        if ($filters->to !== null) {
            $builder->where('v.fecha_salida_planificada <=', $filters->to . ' 23:59:59');
        }
        if ($filters->search !== null) {
            $builder->groupStart()
                ->like('CAST(v.numero AS CHAR)', $filters->search)
                ->orLike('v.observaciones', $filters->search)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->get($perPage, max(0, ($page - 1) * $perPage))->getResultArray();
        if ($rows === []) {
            return ['items' => [], 'total' => $total];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $assignments = $this->assignmentsFor($companyId, $ids);
        $stops = $this->stopsFor($companyId, $ids);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $tripAssignments = $assignments[$id] ?? [];
            $tripStops = $stops[$id] ?? [];
            $items[] = [
                'id' => $id,
                'numero' => (int) $row['numero'],
                'estado' => (string) $row['estado'],
                'estado_label' => self::STATUS_LABELS[(string) $row['estado']] ?? (string) $row['estado'],
                'sucursal_id' => (int) $row['sucursal_id'],
                'fecha_salida_planificada' => $row['fecha_salida_planificada'],
                'fecha_regreso_planificada' => $row['fecha_regreso_planificada'],
                'fecha_salida_real' => $row['fecha_salida_real'],
                'km_salida' => $row['km_salida'] === null ? null : (int) $row['km_salida'],
                'tractor' => $this->roleLabel($tripAssignments, 'TRACTOR'),
                'acoplado' => $this->roleLabel($tripAssignments, 'ACOPLADO'),
                'choferes' => $this->roleLabels($tripAssignments, 'CHOFER'),
                'origen' => $tripStops[0]['ubicacion'] ?? null,
                'destino' => $tripStops === [] ? null : $tripStops[count($tripStops) - 1]['ubicacion'],
                'paradas' => count($tripStops),
            ];
        }

        return ['items' => $items, 'total' => (int) $total];
    }

    public function find(int $companyId, ?array $branchIds, int $tripId): ?array
    {
        if ($branchIds === []) {
            return null;
        }

        $builder = $this->db->table('viajes_operativos v')
            ->select('v.*')
            ->where('v.empresa_id', $companyId)
            ->where('v.id', $tripId);
        if ($branchIds !== null) {
            $builder->whereIn('v.sucursal_id', $branchIds);
        }
        $row = $builder->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        $branchName = $this->db->table('sucursales')
            ->select('nombre')
            ->where('id', (int) $row['sucursal_id'])
            ->where('empresa_id', $companyId)
            ->get()->getRowArray();

        return [
            'trip' => [
                'id' => (int) $row['id'],
                'numero' => (int) $row['numero'],
                'estado' => (string) $row['estado'],
                'estado_label' => self::STATUS_LABELS[(string) $row['estado']] ?? (string) $row['estado'],
                'sucursal_id' => (int) $row['sucursal_id'],
                'sucursal' => $branchName === null ? null : (string) $branchName['nombre'],
                'fecha_salida_planificada' => $row['fecha_salida_planificada'],
                'fecha_regreso_planificada' => $row['fecha_regreso_planificada'],
                'fecha_salida_real' => $row['fecha_salida_real'],
                'fecha_regreso_real' => $row['fecha_regreso_real'],
                'km_salida' => $row['km_salida'] === null ? null : (int) $row['km_salida'],
                'km_regreso' => $row['km_regreso'] === null ? null : (int) $row['km_regreso'],
                'observaciones' => $row['observaciones'],
                'motivo_cancelacion' => $row['motivo_cancelacion'],
                'created_at' => $row['created_at'],
            ],
            'assignments' => $this->assignmentDetails($companyId, $tripId),
            'stops' => $this->stopDetails($companyId, $tripId),
        ];
    }

    /**
     * @param list<int> $ids
     * @return array<int,list<array<string,mixed>>>
     */
    private function assignmentsFor(int $companyId, array $ids): array
    {
        $rows = $this->db->table('viaje_operativo_asignaciones a')
            ->select('a.viaje_id, a.rol, a.equipo_id, a.empleado_id, e.patente, e.codigo, emp.nombre, emp.apellido')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'left')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'left')
            ->where('a.empresa_id', $companyId)
            ->whereIn('a.viaje_id', $ids)
            ->orderBy('a.id', 'ASC')
            ->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['viaje_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<int> $ids
     * @return array<int,list<array<string,mixed>>>
     */
    private function stopsFor(int $companyId, array $ids): array
    {
        $rows = $this->db->table('viaje_operativo_paradas')
            ->select('viaje_id, orden, tipo, ubicacion')
            ->where('empresa_id', $companyId)
            ->whereIn('viaje_id', $ids)
            ->orderBy('viaje_id', 'ASC')
            ->orderBy('orden', 'ASC')
            ->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['viaje_id']][] = $row;
        }

        return $grouped;
    }

    /** @return list<array<string,mixed>> */
    private function assignmentDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_asignaciones a')
            ->select('a.rol, a.desde, a.hasta, a.equipo_id, a.empleado_id, e.patente, e.codigo, emp.nombre, emp.apellido')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'left')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'left')
            ->where('a.empresa_id', $companyId)
            ->where('a.viaje_id', $tripId)
            ->orderBy('a.id', 'ASC')
            ->get()->getResultArray();

        return array_map(function (array $row): array {
            $role = (string) $row['rol'];
            $label = $role === 'CHOFER'
                ? trim((string) $row['nombre'] . ' ' . (string) ($row['apellido'] ?? ''))
                : $this->equipmentLabel($row);

            return [
                'rol' => $role,
                'rol_label' => match ($role) {
                    'TRACTOR' => 'Tractor / camión',
                    'ACOPLADO' => 'Acoplado',
                    default => 'Chofer',
                },
                'label' => $label,
                'desde' => $row['desde'],
                'hasta' => $row['hasta'],
            ];
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    private function stopDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_paradas p')
            ->select('p.*, c.nombre AS cliente_nombre, d.alias AS domicilio_alias, d.calle AS domicilio_calle, d.localidad AS domicilio_localidad, u.nombre AS unidad_nombre, u.simbolo AS unidad_simbolo')
            ->join('clientes c', 'c.id = p.cliente_id AND c.empresa_id = p.empresa_id', 'left')
            ->join('viajes_domicilios_clientes d', 'd.id = p.domicilio_id AND d.empresa_id = p.empresa_id', 'left')
            ->join('viajes_unidades_carga u', 'u.id = p.unidad_carga_id', 'left')
            ->where('p.empresa_id', $companyId)
            ->where('p.viaje_id', $tripId)
            ->orderBy('p.orden', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $row): array {
            $address = trim(implode(' · ', array_filter([
                (string) ($row['domicilio_alias'] ?? ''),
                (string) ($row['domicilio_calle'] ?? ''),
                (string) ($row['domicilio_localidad'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== '')));

            return [
                'orden' => (int) $row['orden'],
                'tipo' => (string) $row['tipo'],
                'tipo_label' => match ((string) $row['tipo']) {
                    'CARGA' => 'Carga',
                    'DESCARGA' => 'Descarga',
                    default => 'Hito',
                },
                'ubicacion' => (string) $row['ubicacion'],
                'cliente' => $row['cliente_nombre'] === null ? null : (string) $row['cliente_nombre'],
                'domicilio' => $address !== '' ? $address : null,
                'fecha_estimada' => $row['fecha_estimada'],
                'fecha_real' => $row['fecha_real'],
                'descripcion_carga' => $row['descripcion_carga'],
                'cantidad' => $row['cantidad'],
                'unidad' => $row['unidad_nombre'] === null ? null : trim((string) $row['unidad_nombre'] . ' ' . (string) ($row['unidad_simbolo'] ?? '')),
                'observaciones' => $row['observaciones'],
            ];
        }, $rows);
    }

    /** @param list<array<string,mixed>> $assignments */
    private function roleLabel(array $assignments, string $role): ?string
    {
        $labels = $this->roleLabels($assignments, $role);

        return $labels[0] ?? null;
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @return list<string>
     */
    private function roleLabels(array $assignments, string $role): array
    {
        $labels = [];
        foreach ($assignments as $assignment) {
            if ((string) $assignment['rol'] !== $role) {
                continue;
            }
            $label = $role === 'CHOFER'
                ? trim((string) ($assignment['nombre'] ?? '') . ' ' . (string) ($assignment['apellido'] ?? ''))
                : $this->equipmentLabel($assignment);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /** @param array<string,mixed> $row */
    private function equipmentLabel(array $row): string
    {
        $plate = trim((string) ($row['patente'] ?? ''));
        $code = trim((string) ($row['codigo'] ?? ''));

        return $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code;
    }
}
