<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\WorkOrders\Port\WorkOrderPrintReadModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterWorkOrderPrintReadModel implements WorkOrderPrintReadModel
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function findScoped(int $companyId, ?array $branchIds, int $orderId): ?array
    {
        $builder = $this->database->table('ordenes_trabajo o')
            ->select('o.id, o.numero, o.estado, o.prioridad, o.fecha_apertura, o.fecha_inicio, o.fecha_finalizacion, o.km_ingreso, o.horas_ingreso, o.km_salida, o.horas_salida, e.id equipo_id, e.codigo equipo_codigo, e.patente equipo_patente, e.chasis equipo_chasis, s.nombre sucursal_nombre, ts.nombre servicio_nombre, u.nombre responsable_nombre')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->join('sucursales s', 's.id = o.sucursal_id AND s.empresa_id = o.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = o.tipo_servicio_id', 'left')
            ->join('usuarios u', 'u.id = o.responsable_usuario_id', 'left')
            ->where('o.empresa_id', $companyId)
            ->where('o.id', $orderId);
        $this->scopeBranches($builder, 'o.sucursal_id', $branchIds);
        $order = $builder->get()->getRowArray();
        if ($order === null) {
            return null;
        }

        $tasks = $this->database->table('orden_tareas')
            ->select('id, descripcion_solicitada, trabajo_realizado, estado, obligatoria, orden, observaciones')
            ->where('empresa_id', $companyId)
            ->where('orden_id', $orderId)
            ->orderBy('orden', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $order['tasks'] = array_map(static fn (array $task): array => [
            'id' => (int) $task['id'],
            'description' => (string) $task['descripcion_solicitada'],
            'workDone' => $task['trabajo_realizado'] === null ? null : (string) $task['trabajo_realizado'],
            'status' => (string) $task['estado'],
            'required' => (int) $task['obligatoria'] === 1,
            'observations' => $task['observaciones'] === null ? null : (string) $task['observaciones'],
        ], $tasks);
        $order['observaciones'] = implode("\n", array_values(array_filter(array_map(
            static fn (array $task): ?string => $task['observations'] ?? null,
            $order['tasks'],
        ))));

        return $order;
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
