<?php

declare(strict_types=1);

namespace App\Infrastructure\MaintenanceCircuit;

use App\Application\MaintenanceCircuit\CircuitOverviewPagination;
use App\Application\MaintenanceCircuit\Port\CircuitOverviewPort;
use App\Infrastructure\PreventiveMaintenance\DecimalHours;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterCircuitOverview implements CircuitOverviewPort
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function fetch(int $companyId, ?array $branchIds, CircuitOverviewPagination $pagination): array
    {
        $company = $this->database->table('empresas')
            ->select('id, razon_social, nombre_fantasia')
            ->where('id', $companyId)->where('estado', 1)->where('deleted_at', null)
            ->get()->getRowArray();
        if ($company === null) {
            throw new DomainException('La empresa no existe o está inactiva.');
        }

        $branches = $this->database->table('sucursales')
            ->select('id, codigo, nombre')->where('empresa_id', $companyId)
            ->where('estado', 1)->where('deleted_at', null)->orderBy('nombre');
        $this->scopeBranches($branches, 'id', $branchIds);

        $equipmentPage = $this->paginate(function () use ($companyId, $branchIds): BaseBuilder {
            $builder = $this->database->table('equipos e')
                ->select('e.id, e.sucursal_id, e.tipo_equipo_id, e.codigo, e.patente, e.km_actual, e.horas_actuales, e.estado, e.fecha_alta, s.nombre sucursal_nombre, te.nombre tipo_nombre, te.controla_km, te.controla_horas')
                ->join('sucursales s', 's.id = e.sucursal_id', 'inner')
                ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
                ->where('e.empresa_id', $companyId)->where('e.deleted_at', null)
                ->orderBy('e.codigo');
            $this->scopeBranches($builder, 'e.sucursal_id', $branchIds);
            return $builder;
        }, $pagination, 'equipments');
        $equipmentRows = $equipmentPage['items'];

        $planPage = $this->paginate(function () use ($companyId, $branchIds): BaseBuilder {
            $builder = $this->database->table('planes_mantenimiento p')
                ->select('p.*, e.sucursal_id, e.codigo equipo_codigo, e.km_actual, e.horas_actuales, ts.nombre servicio_nombre')
                ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
                ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
                ->where('p.empresa_id', $companyId)->where('p.activo', 1)->where('p.deleted_at', null)
                ->orderBy('e.codigo');
            $this->scopeBranches($builder, 'e.sucursal_id', $branchIds);
            return $builder;
        }, $pagination, 'plans');

        $noticePage = $this->paginate(function () use ($companyId, $branchIds): BaseBuilder {
            $builder = $this->database->table('avisos_plan a')
                ->select('a.id, a.plan_id, a.equipo_id, a.estado_calculado, a.criterios_disparadores, a.fecha_deteccion, a.estado_gestion, e.sucursal_id, e.codigo equipo_codigo, ts.nombre servicio_nombre')
                ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
                ->join('planes_mantenimiento p', 'p.id = a.plan_id AND p.empresa_id = a.empresa_id', 'inner')
                ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
                ->where('a.empresa_id', $companyId)->where('a.estado_gestion', 'PENDIENTE')
                ->orderBy('a.fecha_deteccion', 'DESC');
            $this->scopeBranches($builder, 'e.sucursal_id', $branchIds);
            return $builder;
        }, $pagination, 'notices');

        $orderPage = $this->paginate(function () use ($companyId, $branchIds): BaseBuilder {
            $builder = $this->database->table('ordenes_trabajo o')
                ->select('o.id, o.numero, o.sucursal_id, o.equipo_id, o.plan_id, o.aviso_plan_id, o.prioridad, o.responsable_usuario_id, o.fecha_apertura, o.fecha_inicio, o.fecha_finalizacion, o.km_ingreso, o.horas_ingreso, o.km_salida, o.horas_salida, o.estado, e.codigo equipo_codigo, e.km_actual, e.horas_actuales, te.controla_km, te.controla_horas, ts.nombre servicio_nombre, u.nombre responsable_nombre')
                ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
                ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
                ->join('tipos_servicio ts', 'ts.id = o.tipo_servicio_id', 'left')
                ->join('usuarios u', 'u.id = o.responsable_usuario_id', 'left')
                ->where('o.empresa_id', $companyId)->orderBy('o.id', 'DESC');
            $this->scopeBranches($builder, 'o.sucursal_id', $branchIds);
            return $builder;
        }, $pagination, 'orders');
        $orderRows = $orderPage['items'];

        $tasksByOrder = [];
        if ($orderRows !== []) {
            $taskRows = $this->database->table('orden_tareas')
                ->select('id, orden_id, descripcion_solicitada, trabajo_realizado, estado, obligatoria, orden')
                ->where('empresa_id', $companyId)
                ->whereIn('orden_id', array_column($orderRows, 'id'))
                ->orderBy('orden_id')->orderBy('orden')->get()->getResultArray();
            foreach ($taskRows as $task) {
                $tasksByOrder[(int) $task['orden_id']][] = $task;
            }
        }
        foreach ($orderRows as &$order) {
            $order['tasks'] = $tasksByOrder[(int) $order['id']] ?? [];
        }
        unset($order);

        $readingPage = $this->paginate(function () use ($companyId, $branchIds): BaseBuilder {
            $builder = $this->database->table('lecturas_equipo l')
                ->select('l.id, l.equipo_id, l.fecha_lectura, l.kilometraje, l.horometro, l.origen, l.motivo_correccion, e.codigo equipo_codigo, s.nombre sucursal_nombre')
                ->join('equipos e', 'e.id = l.equipo_id AND e.empresa_id = l.empresa_id', 'inner')
                ->join('sucursales s', 's.id = l.sucursal_id AND s.empresa_id = l.empresa_id', 'inner')
                ->where('l.empresa_id', $companyId)->where('l.anulada', 0)
                ->orderBy('l.fecha_lectura', 'DESC');
            $this->scopeBranches($builder, 'l.sucursal_id', $branchIds);
            return $builder;
        }, $pagination, 'readings');

        return [
            'company' => $company,
            'branches' => $branches->get()->getResultArray(),
            'equipmentTypes' => $this->database->table('tipos_equipo')->select('id, nombre, controla_km, controla_horas')->where('activo', 1)->orderBy('nombre')->get()->getResultArray(),
            'serviceTypes' => $this->database->table('tipos_servicio')->select('id, codigo, nombre')->where('activo', 1)->orderBy('nombre')->get()->getResultArray(),
            'templateDefaults' => $this->templateDefaults($companyId),
            'users' => $this->database->table('usuarios')->select('id, nombre')->where('empresa_id', $companyId)->where('activo', 1)->where('es_superadmin', 0)->where('deleted_at', null)->orderBy('nombre')->get()->getResultArray(),
            'equipments' => $equipmentRows,
            'readings' => $readingPage['items'],
            'plans' => $planPage['items'],
            'notices' => $noticePage['items'],
            'orders' => $orderRows,
            'pagination' => [
                'equipments' => $this->metadata($equipmentPage),
                'plans' => $this->metadata($planPage),
                'notices' => $this->metadata($noticePage),
                'orders' => $this->metadata($orderPage),
                'readings' => $this->metadata($readingPage),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function templateDefaults(int $companyId): array
    {
        if (! $this->database->tableExists('plantillas_mantenimiento') || ! $this->database->tableExists('plantilla_mantenimiento_items')) {
            return [];
        }

        $rows = $this->database->table('plantilla_mantenimiento_items i')
            ->select('i.id, i.plantilla_id, i.tipo_servicio_id, i.intervalo_km, i.intervalo_horas, i.intervalo_dias, i.anticipacion_km, i.anticipacion_horas, i.anticipacion_dias, i.prioridad, i.observaciones, p.nombre plantilla_nombre, p.tipo_equipo_id, te.nombre tipo_equipo_nombre, ts.nombre servicio_nombre')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->join('tipos_equipo te', 'te.id = p.tipo_equipo_id', 'left')
            ->join('tipos_servicio ts', 'ts.id = i.tipo_servicio_id', 'inner')
            ->where('p.empresa_id', $companyId)
            ->where('p.activo', 1)
            ->where('p.deleted_at', null)
            ->where('i.activo', 1)
            ->where('ts.activo', 1)
            ->orderBy('p.nombre', 'ASC')
            ->orderBy('ts.nombre', 'ASC')
            ->get()->getResultArray();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'template_id' => (int) $row['plantilla_id'],
            'template_name' => (string) $row['plantilla_nombre'],
            'equipment_type_id' => (int) $row['tipo_equipo_id'],
            'equipment_type_name' => (string) $row['tipo_equipo_nombre'],
            'service_type_id' => (int) $row['tipo_servicio_id'],
            'service_name' => (string) $row['servicio_nombre'],
            'interval_km' => $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
            'interval_hours' => $this->decimalHours(DecimalHours::toTenths($row['intervalo_horas'])),
            'interval_days' => $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
            'warning_km' => $row['anticipacion_km'] === null ? null : (int) $row['anticipacion_km'],
            'warning_hours' => $this->decimalHours(DecimalHours::toTenths($row['anticipacion_horas'])),
            'warning_days' => $row['anticipacion_dias'] === null ? null : (int) $row['anticipacion_dias'],
            'priority' => (string) $row['prioridad'],
            'notes' => $row['observaciones'] === null ? null : (string) $row['observaciones'],
        ], $rows);
    }

    private function decimalHours(?int $tenths): ?string
    {
        return $tenths === null ? null : number_format($tenths / 10, 1, '.', '');
    }

    /**
     * @param callable():BaseBuilder $query
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,totalPages:int}
     */
    private function paginate(callable $query, CircuitOverviewPagination $pagination, string $list): array
    {
        $total = $query()->countAllResults();
        $perPage = $pagination->pageSize($list);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($pagination->page($list), $totalPages);
        $items = $query()->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return compact('items', 'total', 'page', 'perPage', 'totalPages');
    }

    /** @param array{total:int,page:int,perPage:int,totalPages:int} $page */
    private function metadata(array $page): array
    {
        return [
            'total' => $page['total'],
            'page' => $page['page'],
            'perPage' => $page['perPage'],
            'totalPages' => $page['totalPages'],
        ];
    }

    /** @param list<int>|null $branchIds */
    private function scopeBranches(BaseBuilder $builder, string $column, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }
        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);

            return;
        }
        $builder->whereIn($column, $branchIds);
    }
}
