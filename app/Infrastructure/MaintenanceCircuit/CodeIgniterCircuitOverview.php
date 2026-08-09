<?php

declare(strict_types=1);

namespace App\Infrastructure\MaintenanceCircuit;

use App\Application\MaintenanceCircuit\Port\CircuitOverviewPort;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterCircuitOverview implements CircuitOverviewPort
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function fetch(int $companyId, ?array $branchIds): array
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

        $equipments = $this->database->table('equipos e')
            ->select('e.id, e.sucursal_id, e.tipo_equipo_id, e.codigo, e.patente, e.km_actual, e.horas_actuales, e.estado, e.fecha_alta, s.nombre sucursal_nombre, te.nombre tipo_nombre, te.controla_km, te.controla_horas')
            ->join('sucursales s', 's.id = e.sucursal_id', 'inner')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->where('e.empresa_id', $companyId)->where('e.deleted_at', null)
            ->orderBy('e.codigo');
        $this->scopeBranches($equipments, 'e.sucursal_id', $branchIds);
        $equipmentRows = $equipments->get()->getResultArray();

        $plans = $this->database->table('planes_mantenimiento p')
            ->select('p.*, e.sucursal_id, e.codigo equipo_codigo, e.km_actual, e.horas_actuales, ts.nombre servicio_nombre')
            ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
            ->where('p.empresa_id', $companyId)->where('p.activo', 1)->where('p.deleted_at', null)
            ->orderBy('e.codigo');
        $this->scopeBranches($plans, 'e.sucursal_id', $branchIds);

        $notices = $this->database->table('avisos_plan a')
            ->select('a.id, a.plan_id, a.equipo_id, a.estado_calculado, a.criterios_disparadores, a.fecha_deteccion, a.estado_gestion, e.sucursal_id, e.codigo equipo_codigo, ts.nombre servicio_nombre')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->join('planes_mantenimiento p', 'p.id = a.plan_id AND p.empresa_id = a.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
            ->where('a.empresa_id', $companyId)->where('a.estado_gestion', 'PENDIENTE')
            ->orderBy('a.fecha_deteccion', 'DESC');
        $this->scopeBranches($notices, 'e.sucursal_id', $branchIds);

        $orders = $this->database->table('ordenes_trabajo o')
            ->select('o.id, o.numero, o.sucursal_id, o.equipo_id, o.plan_id, o.aviso_plan_id, o.prioridad, o.responsable_usuario_id, o.fecha_apertura, o.fecha_inicio, o.fecha_finalizacion, o.km_ingreso, o.horas_ingreso, o.km_salida, o.horas_salida, o.estado, e.codigo equipo_codigo, ts.nombre servicio_nombre, u.nombre responsable_nombre')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = o.tipo_servicio_id', 'left')
            ->join('usuarios u', 'u.id = o.responsable_usuario_id', 'left')
            ->where('o.empresa_id', $companyId)->orderBy('o.id', 'DESC')->limit(30);
        $this->scopeBranches($orders, 'o.sucursal_id', $branchIds);
        $orderRows = $orders->get()->getResultArray();

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

        $readings = $this->database->table('lecturas_equipo l')
            ->select('l.id, l.equipo_id, l.fecha_lectura, l.kilometraje, l.horometro, l.origen, l.motivo_correccion, e.codigo equipo_codigo')
            ->join('equipos e', 'e.id = l.equipo_id AND e.empresa_id = l.empresa_id', 'inner')
            ->where('l.empresa_id', $companyId)->where('l.anulada', 0)
            ->orderBy('l.fecha_lectura', 'DESC')->limit(20);
        $this->scopeBranches($readings, 'l.sucursal_id', $branchIds);

        return [
            'company' => $company,
            'branches' => $branches->get()->getResultArray(),
            'equipmentTypes' => $this->database->table('tipos_equipo')->select('id, nombre, controla_km, controla_horas')->where('activo', 1)->orderBy('nombre')->get()->getResultArray(),
            'serviceTypes' => $this->database->table('tipos_servicio')->select('id, codigo, nombre')->where('activo', 1)->orderBy('nombre')->get()->getResultArray(),
            'users' => $this->database->table('usuarios')->select('id, nombre')->where('empresa_id', $companyId)->where('activo', 1)->where('es_superadmin', 0)->where('deleted_at', null)->orderBy('nombre')->get()->getResultArray(),
            'equipments' => $equipmentRows,
            'readings' => $readings->get()->getResultArray(),
            'plans' => $plans->get()->getResultArray(),
            'notices' => $notices->get()->getResultArray(),
            'orders' => $orderRows,
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
