<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\OperationalTripDashboardReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterOperationalTripDashboardReadModel implements OperationalTripDashboardReadModel
{
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

    public function trips(int $companyId, ?array $branchIds, ?int $branchId): array
    {
        if ($branchIds === []) {
            return [];
        }

        $fuel = $this->db->prefixTable('viaje_operativo_combustible');
        $expenses = $this->db->prefixTable('viaje_operativo_gastos');
        $legs = $this->db->prefixTable('viaje_operativo_tramos');

        $builder = $this->db->table('viajes_operativos v')
            ->select('v.id, v.numero, v.estado, v.sucursal_id, v.fecha_salida_planificada, v.fecha_regreso_planificada, v.fecha_salida_real, v.fecha_regreso_real, v.km_salida, v.km_regreso, COALESCE(f.litros, 0) AS litros, COALESCE(f.importe_ars, 0) AS combustible_ars, COALESCE(g.importe_ars, 0) AS gastos_ars, COALESCE(l.flete_facturacion_ars, 0) AS flete_facturacion_ars')
            ->join(
                "(SELECT viaje_id, SUM(litros) AS litros, SUM(importe_ars) AS importe_ars FROM {$fuel} GROUP BY viaje_id) f",
                'f.viaje_id = v.id',
                'left',
                false,
            )
            ->join(
                "(SELECT viaje_id, SUM(importe_ars) AS importe_ars FROM {$expenses} GROUP BY viaje_id) g",
                'g.viaje_id = v.id',
                'left',
                false,
            )
            ->join(
                "(SELECT viaje_id, SUM(flete_facturacion_ars) AS flete_facturacion_ars FROM {$legs} GROUP BY viaje_id) l",
                'l.viaje_id = v.id',
                'left',
                false,
            )
            ->where('v.empresa_id', $companyId)
            ->orderBy('v.fecha_salida_planificada', 'ASC')
            ->orderBy('v.id', 'ASC');
        if ($branchIds !== null) {
            $builder->whereIn('v.sucursal_id', $branchIds);
        }
        if ($branchId !== null) {
            $builder->where('v.sucursal_id', $branchId);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'numero' => (int) $row['numero'],
            'estado' => (string) $row['estado'],
            'sucursal_id' => (int) $row['sucursal_id'],
            'fecha_salida_planificada' => (string) $row['fecha_salida_planificada'],
            'fecha_regreso_planificada' => (string) $row['fecha_regreso_planificada'],
            'fecha_salida_real' => $row['fecha_salida_real'],
            'fecha_regreso_real' => $row['fecha_regreso_real'],
            'km_salida' => $row['km_salida'] === null ? null : (int) $row['km_salida'],
            'km_regreso' => $row['km_regreso'] === null ? null : (int) $row['km_regreso'],
            'litros' => (float) $row['litros'],
            'combustible_ars' => (float) $row['combustible_ars'],
            'gastos_ars' => (float) $row['gastos_ars'],
            'flete_facturacion_ars' => (float) $row['flete_facturacion_ars'],
        ], $builder->get()->getResultArray());
    }

    public function details(int $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $details = [];
        foreach ($tripIds as $tripId) {
            $details[(int) $tripId] = [
                'tractor' => null,
                'choferes' => [],
                'origen' => null,
                'destino' => null,
                'paradas' => 0,
            ];
        }

        $assignments = $this->db->table('viaje_operativo_asignaciones a')
            ->select('a.viaje_id, a.rol, e.patente, e.codigo, emp.nombre, emp.apellido')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'left')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'left')
            ->where('a.empresa_id', $companyId)
            ->whereIn('a.viaje_id', $tripIds)
            ->orderBy('a.id', 'ASC')
            ->get()->getResultArray();

        foreach ($assignments as $assignment) {
            $tripId = (int) $assignment['viaje_id'];
            $role = (string) $assignment['rol'];
            if ($role === 'TRACTOR' && $details[$tripId]['tractor'] === null) {
                $details[$tripId]['tractor'] = $this->equipmentLabel($assignment);
            }
            if ($role === 'CHOFER') {
                $name = trim((string) ($assignment['nombre'] ?? '') . ' ' . (string) ($assignment['apellido'] ?? ''));
                if ($name !== '') {
                    $details[$tripId]['choferes'][] = $name;
                }
            }
        }

        $stops = $this->db->table('viaje_operativo_paradas')
            ->select('viaje_id, ubicacion')
            ->where('empresa_id', $companyId)
            ->whereIn('viaje_id', $tripIds)
            ->orderBy('viaje_id', 'ASC')
            ->orderBy('orden', 'ASC')
            ->get()->getResultArray();

        foreach ($stops as $stop) {
            $tripId = (int) $stop['viaje_id'];
            $location = (string) $stop['ubicacion'];
            $details[$tripId]['paradas']++;
            if ($details[$tripId]['origen'] === null) {
                $details[$tripId]['origen'] = $location;
            }
            $details[$tripId]['destino'] = $location;
        }

        return $details;
    }

    /** @param array<string,mixed> $row */
    private function equipmentLabel(array $row): string
    {
        $plate = trim((string) ($row['patente'] ?? ''));
        $code = trim((string) ($row['codigo'] ?? ''));

        return $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code;
    }
}
