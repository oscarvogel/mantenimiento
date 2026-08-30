<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterEquipmentWorkOrderHistory
{
    public function __construct(private BaseConnection $database)
    {
    }

    /**
     * @param array{q?:string,type?:string,from?:string,to?:string} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,totalPages:int}
     */
    public function search(ActorContext $actor, int $equipmentId, array $filters, int $page, int $perPage): array
    {
        $base = fn (): BaseBuilder => $this->filteredBuilder($actor, $equipmentId, $filters);
        $total = $base()->countAllResults();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        $items = $base()
            ->select("o.id, o.numero, o.origen, o.estado, o.fecha_apertura, o.fecha_finalizacion, o.km_ingreso, o.horas_ingreso, o.km_salida, o.horas_salida, o.diagnostico, o.trabajo_realizado, o.observaciones, CASE WHEN o.origen = 'CORRECTIVO' THEN 'OT correctiva' ELSE COALESCE(ts.nombre, 'Mantenimiento preventivo') END servicio_nombre", false)
            ->join('tipos_servicio ts', 'ts.id = o.tipo_servicio_id', 'left')
            ->orderBy('COALESCE(o.fecha_finalizacion, o.fecha_apertura)', 'DESC', false)
            ->orderBy('o.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        $orderIds = array_map(static fn (array $row): int => (int) $row['id'], $items);
        $companyId = (int) $actor->companyId();
        $tasks = $this->tasksByOrder($companyId, $orderIds);
        $evidence = (new CodeIgniterEquipmentWorkOrderEvidenceReadModel($this->database))
            ->forOrders($actor, $equipmentId, $orderIds);
        foreach ($items as &$row) {
            $orderId = (int) $row['id'];
            $row['tasks'] = $tasks[$orderId] ?? [];
            $row['evidence'] = $evidence[$orderId] ?? [];
        }
        unset($row);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /** @param array{q?:string,type?:string,from?:string,to?:string} $filters */
    private function filteredBuilder(ActorContext $actor, int $equipmentId, array $filters): BaseBuilder
    {
        $companyId = (int) $actor->companyId();
        $builder = $this->database->table('ordenes_trabajo o')
            ->where('o.empresa_id', $companyId)
            ->where('o.equipo_id', $equipmentId);

        if (! $actor->hasAllCompanyBranches()) {
            $branchIds = $actor->branchIds();
            if ($branchIds === []) {
                $builder->where('1 = 0', null, false);
            } else {
                $builder->whereIn('o.sucursal_id', $branchIds);
            }
        }
        if (! $actor->hasPermission('ordenes.ver') && $actor->hasPermission('ordenes.mi_trabajo')) {
            $builder->where('o.responsable_usuario_id', $actor->userId());
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $escaped = $this->database->escapeLikeString($q);
            $needle = $this->database->escape('%' . $escaped . '%');
            $builder->groupStart()
                ->like('o.numero', $q)
                ->orLike('o.diagnostico', $q)
                ->orLike('o.trabajo_realizado', $q)
                ->orLike('o.observaciones', $q)
                ->orWhere("EXISTS (SELECT 1 FROM orden_tareas ht WHERE ht.empresa_id = o.empresa_id AND ht.orden_id = o.id AND (ht.descripcion_solicitada LIKE {$needle} ESCAPE '!' OR ht.trabajo_realizado LIKE {$needle} ESCAPE '!' OR ht.observaciones LIKE {$needle} ESCAPE '!'))", null, false)
                ->groupEnd();
        }

        $type = strtoupper(trim((string) ($filters['type'] ?? '')));
        if ($type === 'CORRECTIVO') {
            $builder->where('o.origen', 'CORRECTIVO');
        } elseif ($type === 'PREVENTIVO') {
            $builder->where('o.origen !=', 'CORRECTIVO');
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $builder->where(
                'DATE(COALESCE(o.fecha_finalizacion, o.fecha_apertura)) >= ' . $this->database->escape($from),
                null,
                false,
            );
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $builder->where(
                'DATE(COALESCE(o.fecha_finalizacion, o.fecha_apertura)) <= ' . $this->database->escape($to),
                null,
                false,
            );
        }

        return $builder;
    }

    /** @param list<int> $orderIds @return array<int,list<array<string,mixed>>> */
    private function tasksByOrder(int $companyId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->database->table('orden_tareas')
            ->select('id, orden_id, descripcion_solicitada, trabajo_realizado, estado, observaciones')
            ->where('empresa_id', $companyId)
            ->whereIn('orden_id', $orderIds)
            ->orderBy('orden_id', 'ASC')
            ->orderBy('orden', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['orden_id']][] = $row;
        }

        return $grouped;
    }

}
