<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Assets\Port\EquipmentFleetLookup;
use App\Application\Trips\Port\TripFormReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripFormReadModel implements TripFormReadModel
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly EquipmentFleetLookup $fleet,
    ) {
    }

    public function options(int $companyId, ?array $branchIds): array
    {
        $company = $this->db->table('empresas')
            ->select('razon_social, nombre_fantasia, cuit')
            ->where('id', $companyId)
            ->get()->getRowArray();
        $counter = $this->db->table('viajes_operativos_numeracion')
            ->select('ultimo_numero')
            ->where('empresa_id', $companyId)
            ->get()->getRowArray();
        $nextNumber = (int) ($counter['ultimo_numero'] ?? 0) + 1;

        if ($branchIds === []) {
            return [
                'company' => $this->companyData($company),
                'nextNumber' => $nextNumber,
                'branches' => [],
                'tractors' => [],
                'trailers' => [],
                'drivers' => [],
                'customers' => [],
                'addresses' => [],
                'loadUnits' => [],
                'currencies' => [],
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

        $equipmentRows = $equipment->get()->getResultArray();
        $equipmentIds = array_map(static fn (array $row): int => (int) $row['id'], $equipmentRows);
        $lastKmByEquipment = $this->lastKmByEquipment($companyId, $equipmentIds);

        $tractors = [];
        $trailers = [];
        foreach ($equipmentRows as $row) {
            $type = strtr(mb_strtolower((string) $row['tipo_nombre']), [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            ]);
            $item = [
                'id' => (int) $row['id'],
                'label' => $this->equipmentLabel($row),
                'plate' => trim((string) ($row['patente'] ?? '')),
                'type' => (string) $row['tipo_nombre'],
                'branchId' => (int) $row['sucursal_id'],
                'lastKm' => $lastKmByEquipment[(int) $row['id']] ?? null,
            ];
            if (preg_match('/acoplado|semirremolque|remolque|batea/', $type) === 1) {
                $trailers[] = $item;
            }
            if (preg_match('/camion|tractor|chasis|cabina|utilitario|pickup/', $type) === 1) {
                $tractors[] = $item;
            }
        }

        // Los semirremolques que hoy estan enganchados a cada tractor. El
        // formulario los usa para preseleccionar el acoplado y para avisar
        // cuando el camion es un bitren con mas de una caja.
        $linkedTrailerIds = $this->linkedTrailerIdsByTractor($companyId, $tractors, $trailers);
        foreach ($tractors as $index => $tractor) {
            $tractors[$index]['linkedTrailerIds'] = $linkedTrailerIds[(int) $tractor['id']] ?? [];
        }

        $drivers = $this->db->table('empleados')
            ->select('id, nombre, apellido, legajo')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('apellido', 'ASC')
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        $percentageByDriver = [];
        $driverIds = array_map(static fn (array $row): int => (int) $row['id'], $drivers);
        if ($driverIds !== []) {
            $today = date('Y-m-d');
            $percentages = $this->db->table('viajes_porcentajes_liquidacion_chofer')
                ->select('empleado_id, porcentaje')
                ->where('empresa_id', $companyId)
                ->where('activo', 1)
                ->where('vigente_desde <=', $today)
                ->groupStart()
                    ->where('vigente_hasta', null)
                    ->orWhere('vigente_hasta >=', $today)
                ->groupEnd()
                ->whereIn('empleado_id', $driverIds)
                ->orderBy('vigente_desde', 'DESC')
                ->orderBy('id', 'DESC')
                ->get()->getResultArray();
            foreach ($percentages as $percentage) {
                $driverId = (int) $percentage['empleado_id'];
                if (! array_key_exists($driverId, $percentageByDriver)) {
                    $percentageByDriver[$driverId] = (float) $percentage['porcentaje'];
                }
            }
        }

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
            ->select('id, codigo_origen, nombre, identificacion_fiscal')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        $currencies = $this->db->table('viajes_monedas')
            ->select('codigo, nombre')
            ->where('activo', 1)
            ->orderBy('codigo', 'ASC')
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
            'company' => $this->companyData($company),
            'nextNumber' => $nextNumber,
            'branches' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['codigo'],
                'name' => (string) $row['nombre'],
            ], $branches->get()->getResultArray()),
            'tractors' => $tractors,
            'trailers' => $trailers,
            'drivers' => array_map(static function (array $row) use ($assignedByDriver, $percentageByDriver): array {
                $assignment = $assignedByDriver[(int) $row['id']] ?? null;

                return [
                    'id' => (int) $row['id'],
                    'label' => trim((string) $row['apellido'] . ', ' . (string) $row['nombre']),
                    'employeeNumber' => $row['legajo'] === null ? null : (string) $row['legajo'],
                    'equipmentId' => $assignment['equipmentId'] ?? null,
                    'assignedEquipmentLabel' => $assignment['equipmentLabel'] ?? null,
                    'liquidationPercentage' => $percentageByDriver[(int) $row['id']] ?? null,
                ];
            }, $drivers),
            'customers' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['nombre'],
                'code' => $row['codigo_origen'] === null ? null : (string) $row['codigo_origen'],
                'taxId' => $row['identificacion_fiscal'] === null ? null : (string) $row['identificacion_fiscal'],
            ], $customers),
            'currencies' => array_map(static fn (array $row): array => [
                'code' => (string) $row['codigo'],
                'label' => (string) $row['nombre'],
            ], $currencies),
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

    /**
     * @param array<string,mixed>|null $row
     * @return array{name:string,legalName:string,taxId:?string}
     */
    private function companyData(?array $row): array
    {
        $legalName = trim((string) ($row['razon_social'] ?? ''));
        $displayName = trim((string) ($row['nombre_fantasia'] ?? ''));

        return [
            'name' => $displayName !== '' ? $displayName : $legalName,
            'legalName' => $legalName,
            'taxId' => isset($row['cuit']) && $row['cuit'] !== null ? (string) $row['cuit'] : null,
        ];
    }

    /**
     * Semirremolques enganchados hoy a cada tractor, limitado a los que el
     * operador puede ver: si una caja no esta en la lista, no se preselecciona.
     *
     * @param list<array<string,mixed>> $tractors
     * @param list<array<string,mixed>> $trailers
     *
     * @return array<int, list<int>>
     */
    private function linkedTrailerIdsByTractor(int $companyId, array $tractors, array $trailers): array
    {
        if ($tractors === []) {
            return [];
        }

        $visibleTrailerIds = array_fill_keys(
            array_map(static fn (array $trailer): int => (int) $trailer['id'], $trailers),
            true
        );
        $tractorIds = array_map(static fn (array $tractor): int => (int) $tractor['id'], $tractors);

        $linked = [];
        foreach ($this->fleet->activeTrailers($companyId, $tractorIds) as $tractorId => $items) {
            $ids = [];
            foreach ($items as $item) {
                $id = (int) $item['id'];
                if (isset($visibleTrailerIds[$id])) {
                    $ids[] = $id;
                }
            }
            if ($ids !== []) {
                $linked[(int) $tractorId] = $ids;
            }
        }

        return $linked;
    }

    /**
     * @param list<int> $equipmentIds
     * @return array<int,int>
     */
    private function lastKmByEquipment(int $companyId, array $equipmentIds): array
    {
        if ($equipmentIds === []) {
            return [];
        }

        $rows = $this->db->table('lecturas_equipo')
            ->select('equipo_id, kilometraje')
            ->where('empresa_id', $companyId)
            ->where('anulada', 0)
            ->where('kilometraje IS NOT NULL', null, false)
            ->whereIn('equipo_id', $equipmentIds)
            ->orderBy('fecha_lectura', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $lastKm = [];
        foreach ($rows as $row) {
            $equipmentId = (int) $row['equipo_id'];
            if (! array_key_exists($equipmentId, $lastKm)) {
                $lastKm[$equipmentId] = (int) $row['kilometraje'];
            }
        }

        return $lastKm;
    }

    /** @param array<string,mixed> $row */
    private function equipmentLabel(array $row): string
    {
        $plate = trim((string) ($row['patente'] ?? ''));
        $code = trim((string) ($row['codigo'] ?? ''));

        return $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code;
    }
}
