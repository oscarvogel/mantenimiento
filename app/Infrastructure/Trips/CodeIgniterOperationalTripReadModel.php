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
                'acoplados' => $this->roleLabels($tripAssignments, 'ACOPLADO'),
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
        $company = $this->db->table('empresas')
            ->select('razon_social, nombre_fantasia')
            ->where('id', $companyId)
            ->get()->getRowArray();

        return [
            'company' => [
                'name' => trim((string) ($company['razon_social'] ?? '')) !== ''
                    ? (string) $company['razon_social']
                    : (string) ($company['nombre_fantasia'] ?? ''),
            ],
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
            'legs' => $this->legDetails($companyId, $tripId),
            'stops' => $this->stopDetails($companyId, $tripId),
            'fuel' => $this->fuelDetails($companyId, $tripId),
            'expenses' => $this->expenseDetails($companyId, $tripId),
            'cashMovements' => $this->cashMovementDetails($companyId, $tripId),
            'otherEarnings' => $this->otherEarningDetails($companyId, $tripId),
            'logbook' => $this->logbookDetails($companyId, $tripId),
            'options' => $this->costOptions($companyId),
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
            ->select('a.rol, a.desde, a.hasta, a.equipo_id, a.empleado_id, a.porcentaje_liquidacion, e.patente, e.codigo, emp.nombre, emp.apellido')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'left')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'left')
            ->where('a.empresa_id', $companyId)
            ->where('a.viaje_id', $tripId)
            ->orderBy('a.id', 'ASC')
            ->get()->getResultArray();

        $driverIds = array_values(array_filter(array_map(
            static fn (array $row): ?int => $row['empleado_id'] === null ? null : (int) $row['empleado_id'],
            $rows,
        )));
        $masterPercentages = $this->masterPercentagesForDrivers($companyId, $tripId, $driverIds);

        return array_map(function (array $row) use ($masterPercentages): array {
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
                'empleado_id' => $row['empleado_id'] === null ? null : (int) $row['empleado_id'],
                'desde' => $row['desde'],
                'hasta' => $row['hasta'],
                'porcentaje_liquidacion' => $row['porcentaje_liquidacion'] === null ? null : (float) $row['porcentaje_liquidacion'],
                'master_percentage' => $row['empleado_id'] === null ? null : ($masterPercentages[(int) $row['empleado_id']] ?? null),
            ];
        }, $rows);
    }

    /**
     * Porcentaje vigente del maestro a la fecha de salida del viaje.
     *
     * @param list<int> $driverIds
     * @return array<int,float>
     */
    private function masterPercentagesForDrivers(int $companyId, int $tripId, array $driverIds): array
    {
        if ($driverIds === []) {
            return [];
        }

        $trip = $this->db->table('viajes_operativos')
            ->select('fecha_salida_real, fecha_salida_planificada')
            ->where('empresa_id', $companyId)
            ->where('id', $tripId)
            ->get()->getRowArray();
        $date = substr((string) ($trip['fecha_salida_real'] ?? $trip['fecha_salida_planificada'] ?? ''), 0, 10);
        if ($date === '') {
            return [];
        }

        $rows = $this->db->table('viajes_porcentajes_liquidacion_chofer')
            ->select('empleado_id, porcentaje')
            ->where('empresa_id', $companyId)
            ->whereIn('empleado_id', $driverIds)
            ->where('activo', 1)
            ->where('vigente_desde <=', $date)
            ->groupStart()
            ->where('vigente_hasta', null)
            ->orWhere('vigente_hasta >=', $date)
            ->groupEnd()
            ->orderBy('vigente_desde', 'DESC')
            ->get()->getResultArray();

        $percentages = [];
        foreach ($rows as $row) {
            $id = (int) $row['empleado_id'];
            if (! array_key_exists($id, $percentages)) {
                $percentages[$id] = (float) $row['porcentaje'];
            }
        }

        return $percentages;
    }

    /** @return list<array<string,mixed>> */
    private function legDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_tramos t')
            ->select('t.id, t.orden, t.codigo_origen_destino, t.cliente_id, t.origen, t.destino, t.km, t.toneladas, t.moneda, t.cotizacion, t.flete_facturacion, t.flete_facturacion_ars, t.flete_liquidacion, t.flete_liquidacion_ars, t.observaciones, c.codigo_origen AS cliente_codigo, c.nombre AS cliente')
            ->join('clientes c', 'c.id = t.cliente_id AND c.empresa_id = t.empresa_id', 'left')
            ->where('t.empresa_id', $companyId)
            ->where('t.viaje_id', $tripId)
            ->orderBy('t.orden', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'orden' => (int) $row['orden'],
            'codigo_origen_destino' => $row['codigo_origen_destino'] === null ? null : (int) $row['codigo_origen_destino'],
            'cliente_id' => $row['cliente_id'] === null ? null : (int) $row['cliente_id'],
            'cliente_codigo' => $row['cliente_codigo'] === null ? null : (string) $row['cliente_codigo'],
            'cliente' => $row['cliente'],
            'origen' => (string) $row['origen'],
            'destino' => (string) $row['destino'],
            'km' => $row['km'] === null ? null : (float) $row['km'],
            'toneladas' => $row['toneladas'] === null ? null : (float) $row['toneladas'],
            'moneda' => (string) $row['moneda'],
            'cotizacion' => (float) $row['cotizacion'],
            'flete_facturacion' => (float) $row['flete_facturacion'],
            'flete_facturacion_ars' => (float) $row['flete_facturacion_ars'],
            'flete_liquidacion' => (float) $row['flete_liquidacion'],
            'flete_liquidacion_ars' => (float) $row['flete_liquidacion_ars'],
            'observaciones' => $row['observaciones'],
        ], $rows);
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

    /** @return list<array<string,mixed>> */
    private function fuelDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_combustible')
            ->select('id, tramo_id, fecha, litros, importe, moneda, cotizacion, importe_ars, kilometraje, estacion, comprobante, observaciones')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('deleted_at', null)
            ->orderBy('fecha', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tramo_id' => $row['tramo_id'] === null ? null : (int) $row['tramo_id'],
            'fecha' => $row['fecha'],
            'litros' => (float) $row['litros'],
            'importe' => (float) $row['importe'],
            'moneda' => (string) $row['moneda'],
            'cotizacion' => (float) $row['cotizacion'],
            'importe_ars' => (float) $row['importe_ars'],
            'kilometraje' => $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
            'estacion' => $row['estacion'],
            'comprobante' => $row['comprobante'],
            'observaciones' => $row['observaciones'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function expenseDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_gastos g')
            ->select('g.id, g.tramo_id, g.fecha, g.importe, g.moneda, g.cotizacion, g.importe_ars, g.categoria_id, g.concepto_id, g.cantidad, g.importe_unitario, g.medio_pago_id, g.pagado_de_caja, g.pagado_por, g.comprobante, g.observaciones, c.nombre AS categoria, k.nombre AS concepto_catalogo, m.nombre AS medio_pago')
            ->join('viajes_categorias_gasto c', 'c.id = g.categoria_id AND c.empresa_id = g.empresa_id', 'left')
            ->join('viajes_conceptos k', 'k.id = g.concepto_id AND k.empresa_id = g.empresa_id', 'left')
            ->join('viajes_medios_pago m', 'm.id = g.medio_pago_id AND m.empresa_id = g.empresa_id', 'left')
            ->where('g.empresa_id', $companyId)
            ->where('g.viaje_id', $tripId)
            ->where('g.deleted_at', null)
            ->orderBy('g.fecha', 'ASC')
            ->orderBy('g.id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tramo_id' => $row['tramo_id'] === null ? null : (int) $row['tramo_id'],
            'fecha' => $row['fecha'],
            'importe' => (float) $row['importe'],
            'moneda' => (string) $row['moneda'],
            'cotizacion' => (float) $row['cotizacion'],
            'importe_ars' => (float) $row['importe_ars'],
            'categoria_id' => $row['categoria_id'] === null ? null : (int) $row['categoria_id'],
            'categoria' => $row['categoria'],
            'concepto_id' => $row['concepto_id'] === null ? null : (int) $row['concepto_id'],
            'concepto_catalogo' => $row['concepto_catalogo'],
            'cantidad' => (float) $row['cantidad'],
            'importe_unitario' => $row['importe_unitario'] === null ? null : (float) $row['importe_unitario'],
            'medio_pago_id' => $row['medio_pago_id'] === null ? null : (int) $row['medio_pago_id'],
            'medio_pago' => $row['medio_pago'],
            'pagado_de_caja' => (bool) $row['pagado_de_caja'],
            'pagado_por' => (string) ($row['pagado_por'] ?? ((bool) $row['pagado_de_caja'] ? 'CAJA' : 'EMPRESA')),
            'comprobante' => $row['comprobante'],
            'observaciones' => $row['observaciones'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function cashMovementDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_caja_movimientos')
            ->select('id, tipo, fecha, importe, moneda, cotizacion, importe_ars, observaciones')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('deleted_at', null)
            ->orderBy('fecha', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tipo' => (string) $row['tipo'],
            'fecha' => $row['fecha'],
            'importe' => (float) $row['importe'],
            'moneda' => (string) $row['moneda'],
            'cotizacion' => (float) $row['cotizacion'],
            'importe_ars' => (float) $row['importe_ars'],
            'observaciones' => $row['observaciones'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function otherEarningDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_otros_haberes h')
            ->select('h.id, h.empleado_id, h.fecha, h.concepto, h.concepto_id, h.diario_bordo_id, h.cantidad, h.importe_unitario, h.importe, h.moneda, h.cotizacion, h.importe_ars, h.observaciones, k.nombre AS concepto_catalogo, emp.nombre, emp.apellido')
            ->join('empleados emp', 'emp.id = h.empleado_id AND emp.empresa_id = h.empresa_id', 'left')
            ->join('viajes_conceptos k', 'k.id = h.concepto_id AND k.empresa_id = h.empresa_id', 'left')
            ->where('h.empresa_id', $companyId)
            ->where('h.viaje_id', $tripId)
            ->where('h.deleted_at', null)
            ->orderBy('h.fecha', 'ASC')
            ->orderBy('h.id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'empleado_id' => (int) $row['empleado_id'],
            'empleado' => trim((string) ($row['nombre'] ?? '') . ' ' . (string) ($row['apellido'] ?? '')),
            'fecha' => $row['fecha'],
            'concepto' => (string) $row['concepto'],
            'concepto_id' => $row['concepto_id'] === null ? null : (int) $row['concepto_id'],
            'concepto_catalogo' => $row['concepto_catalogo'],
            'diario_bordo_id' => $row['diario_bordo_id'] === null ? null : (int) $row['diario_bordo_id'],
            'generado_por_diario' => $row['diario_bordo_id'] !== null,
            'cantidad' => (float) $row['cantidad'],
            'importe_unitario' => $row['importe_unitario'] === null ? null : (float) $row['importe_unitario'],
            'importe' => (float) $row['importe'],
            'moneda' => (string) $row['moneda'],
            'cotizacion' => (float) $row['cotizacion'],
            'importe_ars' => (float) $row['importe_ars'],
            'observaciones' => $row['observaciones'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function logbookDetails(int $companyId, int $tripId): array
    {
        $rows = $this->db->table('viaje_operativo_diario_bordo d')
            ->select('d.id, d.tipo, d.orden, d.ubicacion, d.empleado_id, d.concepto_id, d.llegada, d.salida, d.horas_libres, d.dias_calculados, d.dias_ajustados, d.motivo_ajuste, d.observaciones, emp.nombre, emp.apellido, k.nombre AS concepto_catalogo, COALESCE(h.importe_unitario, k.importe_unitario) AS importe_unitario, COALESCE(h.moneda, k.moneda) AS moneda, h.id AS haber_id, h.importe_ars AS haber_importe_ars')
            ->join('empleados emp', 'emp.id = d.empleado_id AND emp.empresa_id = d.empresa_id', 'left')
            ->join('viajes_conceptos k', 'k.id = d.concepto_id AND k.empresa_id = d.empresa_id', 'left')
            ->join('viaje_operativo_otros_haberes h', 'h.diario_bordo_id = d.id AND h.empresa_id = d.empresa_id AND h.deleted_at IS NULL', 'left')
            ->where('d.empresa_id', $companyId)
            ->where('d.viaje_id', $tripId)
            ->where('d.deleted_at', null)
            ->orderBy('d.orden', 'ASC')
            ->orderBy('d.id', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $row): array {
            $calculated = $row['dias_calculados'] === null ? null : (float) $row['dias_calculados'];
            $adjusted = $row['dias_ajustados'] === null ? null : (float) $row['dias_ajustados'];
            $type = (string) $row['tipo'];
            $presence = null;
            if ($row['salida'] !== null) {
                $arrival = new \DateTimeImmutable(substr((string) $row['llegada'], 0, 10));
                $departure = new \DateTimeImmutable(substr((string) $row['salida'], 0, 10));
                $presence = (int) $arrival->diff($departure)->format('%a');
            }

            return [
                'id' => (int) $row['id'],
                'tipo' => $type,
                'tipo_label' => match ($type) {
                    'CARGA' => 'Carga',
                    'FRONTERA' => 'Frontera',
                    'CLIENTE' => 'Cliente / descarga',
                    default => $type,
                },
                'orden' => (int) $row['orden'],
                'ubicacion' => (string) $row['ubicacion'],
                'empleado_id' => (int) $row['empleado_id'],
                'empleado' => trim((string) ($row['nombre'] ?? '') . ' ' . (string) ($row['apellido'] ?? '')),
                'concepto_id' => (int) $row['concepto_id'],
                'concepto' => (string) ($row['concepto_catalogo'] ?? ''),
                'llegada' => $row['llegada'],
                'salida' => $row['salida'],
                'horas_libres' => (int) $row['horas_libres'],
                'dias_permanencia' => $presence,
                'dias_calculados' => $calculated,
                'dias_ajustados' => $adjusted,
                'dias_liquidados' => $adjusted ?? $calculated,
                'motivo_ajuste' => $row['motivo_ajuste'],
                'observaciones' => $row['observaciones'],
                'importe_unitario' => $row['importe_unitario'] === null ? null : (float) $row['importe_unitario'],
                'moneda' => $row['moneda'] === null ? null : (string) $row['moneda'],
                'haber_id' => $row['haber_id'] === null ? null : (int) $row['haber_id'],
                'importe_ars' => $row['haber_importe_ars'] === null ? null : (float) $row['haber_importe_ars'],
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function costOptions(int $companyId): array
    {
        $categories = $this->db->table('viajes_categorias_gasto')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();
        $methods = $this->db->table('viajes_medios_pago')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();
        $concepts = $this->db->table('viajes_conceptos')
            ->select('id, nombre, categoria, unidad, importe_unitario, moneda, permite_editar_importe')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();
        $conceptOptions = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['nombre'],
            'categoria' => strtoupper((string) $row['categoria']),
            'unit' => strtoupper((string) $row['unidad']),
            'amount' => (float) $row['importe_unitario'],
            'currency' => strtoupper((string) $row['moneda']),
            'editable' => (int) $row['permite_editar_importe'] === 1,
        ], $concepts);
        $currencies = $this->db->table('viajes_monedas')
            ->select('codigo, nombre')
            ->where('activo', 1)
            ->orderBy('codigo', 'ASC')
            ->get()->getResultArray();
        $customers = $this->db->table('clientes')
            ->select('id, codigo_origen, nombre')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC')
            ->get()->getResultArray();

        return [
            'expenseCategories' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['nombre'],
            ], $categories),
            'paymentMethods' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['nombre'],
            ], $methods),
            'expenseConcepts' => array_values(array_filter(
                $conceptOptions,
                static fn (array $concept): bool => in_array($concept['categoria'], ['GASTO', 'AMBOS'], true),
            )),
            'earningConcepts' => array_values(array_filter(
                $conceptOptions,
                static fn (array $concept): bool => in_array($concept['categoria'], ['HABER', 'AMBOS'], true),
            )),
            'currencies' => array_map(static fn (array $row): array => [
                'code' => (string) $row['codigo'],
                'label' => (string) $row['nombre'],
            ], $currencies),
            'customers' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['nombre'],
                'code' => (string) $row['codigo_origen'],
            ], $customers),
        ];
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
