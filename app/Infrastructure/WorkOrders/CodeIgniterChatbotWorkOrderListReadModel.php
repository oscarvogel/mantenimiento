<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\Chatbot\Port\WorkOrderListReadModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterChatbotWorkOrderListReadModel implements WorkOrderListReadModel
{
    public function __construct(private BaseConnection $database) {}

    public function listScoped(
        int $companyId,
        ?array $branchIds,
        array $states,
        ?int $equipmentId,
        ?string $origin,
        ?string $from,
        ?string $to,
        int $limit,
    ): array {
        if ($branchIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $count = $this->base($companyId, $branchIds);
        $this->filters($count, $states, $equipmentId, $origin, $from, $to);
        $total = $count->countAllResults();

        $items = $this->base($companyId, $branchIds)
            ->select("o.id, o.numero, o.estado, o.origen, o.prioridad, o.fecha_apertura, o.fecha_inicio, o.fecha_finalizacion, o.km_ingreso, o.horas_ingreso, o.km_salida, o.horas_salida, o.costo_total, e.id equipment_id, e.codigo equipment_code, e.patente equipment_plate, s.nombre branch_name, CASE WHEN o.origen = 'CORRECTIVO' THEN 'OT correctiva' ELSE ts.nombre END service_name", false);
        $this->filters($items, $states, $equipmentId, $origin, $from, $to);
        $rows = $items->orderBy('o.fecha_apertura', 'DESC')->orderBy('o.id', 'DESC')->limit($limit)->get()->getResultArray();

        return ['items' => $rows, 'total' => $total];
    }

    /** @param list<int>|null $branchIds */
    private function base(int $companyId, ?array $branchIds): BaseBuilder
    {
        $builder = $this->database->table('ordenes_trabajo o')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->join('sucursales s', 's.id = o.sucursal_id AND s.empresa_id = o.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = o.tipo_servicio_id', 'left')
            ->where('o.empresa_id', $companyId);

        if ($branchIds !== null) {
            $builder->whereIn('o.sucursal_id', $branchIds);
        }

        return $builder;
    }

    /** @param list<string> $states */
    private function filters(BaseBuilder $builder, array $states, ?int $equipmentId, ?string $origin, ?string $from, ?string $to): void
    {
        if ($states !== []) {
            $builder->whereIn('o.estado', $states);
        }
        if ($equipmentId !== null && $equipmentId > 0) {
            $builder->where('o.equipo_id', $equipmentId);
        }
        if ($origin !== null && $origin !== '') {
            $builder->where('o.origen', strtoupper($origin));
        }
        if ($from !== null && $from !== '') {
            $builder->where('o.fecha_apertura >=', $from . ' 00:00:00');
        }
        if ($to !== null && $to !== '') {
            $builder->where('o.fecha_apertura <=', $to . ' 23:59:59');
        }
    }
}
