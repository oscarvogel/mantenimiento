<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\WorkOrderDashboardReadModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterWorkOrderDashboardReadModel implements WorkOrderDashboardReadModel
{
    private const DELAY_DAYS = 3;
    private const OPEN_STATES = ['EMITIDA', 'EN_PROCESO', 'ESPERA_REPUESTOS'];

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function search(ActorContext $actor, array $filters, int $page, int $perPage): array
    {
        $base = fn (): BaseBuilder => $this->filteredBuilder($actor, $filters);
        $total = $base()->countAllResults();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $items = $base()
            ->orderBy("CASE o.estado WHEN 'EN_PROCESO' THEN 1 WHEN 'ESPERA_REPUESTOS' THEN 2 WHEN 'EMITIDA' THEN 3 ELSE 4 END", '', false)
            ->orderBy('o.fecha_apertura', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        foreach ($items as &$row) {
            $ageDays = max(0, (int) ($row['antiguedad_dias'] ?? 0));
            $row['antiguedad_dias'] = $ageDays;
            $row['demorada'] = in_array((string) $row['estado'], self::OPEN_STATES, true) && $ageDays >= self::DELAY_DAYS;
        }
        unset($row);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'kpis' => $this->kpis($actor),
            'branches' => $this->branches($actor),
            'owners' => $this->owners((int) $actor->companyId()),
            'delayDays' => self::DELAY_DAYS,
        ];
    }

    private function filteredBuilder(ActorContext $actor, array $filters): BaseBuilder
    {
        $builder = $this->baseBuilder($actor);
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $builder->groupStart()
                ->like('o.numero', $q)
                ->orLike('e.codigo', $q)
                ->orLike('e.patente', $q)
                ->groupEnd();
        }
        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        if ($status !== '') {
            $builder->where('o.estado', $status);
        }
        $branchId = (int) ($filters['branch_id'] ?? 0);
        if ($branchId > 0) {
            $builder->where('o.sucursal_id', $branchId);
        }
        $ownerId = (int) ($filters['owner_id'] ?? 0);
        if ($ownerId > 0) {
            $builder->where('o.responsable_usuario_id', $ownerId);
        }
        if (($filters['attention'] ?? '') === 'delayed') {
            $builder->whereIn('o.estado', self::OPEN_STATES)
                ->where('DATEDIFF(CURDATE(), DATE(o.fecha_apertura)) >=', self::DELAY_DAYS, false);
        }

        return $builder;
    }

    private function baseBuilder(ActorContext $actor): BaseBuilder
    {
        $companyId = (int) $actor->companyId();
        $builder = $this->database->table('ordenes_trabajo o')
            ->select("o.id, o.numero, o.origen, o.prioridad, o.estado, o.sucursal_id, o.equipo_id, o.responsable_usuario_id, o.fecha_apertura, o.fecha_inicio, o.fecha_finalizacion, o.km_ingreso, o.horas_ingreso, e.codigo equipo_codigo, e.patente equipo_patente, s.nombre sucursal_nombre, CASE WHEN o.origen = 'CORRECTIVO' THEN 'OT correctiva' ELSE ts.nombre END servicio_nombre, u.nombre responsable_nombre, DATEDIFF(CURDATE(), DATE(o.fecha_apertura)) antiguedad_dias", false)
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->join('sucursales s', 's.id = o.sucursal_id AND s.empresa_id = o.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = o.tipo_servicio_id', 'left')
            ->join('usuarios u', 'u.id = o.responsable_usuario_id', 'left')
            ->where('o.empresa_id', $companyId);

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

        return $builder;
    }

    /** @return array<string,int> */
    private function kpis(ActorContext $actor): array
    {
        $countState = function (string $state) use ($actor): int {
            return $this->baseBuilder($actor)->where('o.estado', $state)->countAllResults();
        };
        $open = $this->baseBuilder($actor)->whereIn('o.estado', self::OPEN_STATES)->countAllResults();
        $delayed = $this->baseBuilder($actor)->whereIn('o.estado', self::OPEN_STATES)
            ->where('DATEDIFF(CURDATE(), DATE(o.fecha_apertura)) >=', self::DELAY_DAYS, false)->countAllResults();
        $finishedToday = $this->baseBuilder($actor)->where('o.estado', 'FINALIZADA')
            ->where('DATE(o.fecha_finalizacion) = CURDATE()', null, false)->countAllResults();

        return [
            'open' => $open,
            'issued' => $countState('EMITIDA'),
            'inProgress' => $countState('EN_PROCESO'),
            'waitingParts' => $countState('ESPERA_REPUESTOS'),
            'delayed' => $delayed,
            'finishedToday' => $finishedToday,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function branches(ActorContext $actor): array
    {
        $builder = $this->database->table('sucursales')->select('id, codigo, nombre')
            ->where('empresa_id', (int) $actor->companyId())->where('estado', 1)->where('deleted_at', null);
        if (! $actor->hasAllCompanyBranches()) {
            $ids = $actor->branchIds();
            if ($ids === []) { return []; }
            $builder->whereIn('id', $ids);
        }
        return $builder->orderBy('nombre')->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    private function owners(int $companyId): array
    {
        return $this->database->table('usuarios')->select('id, nombre')
            ->where('empresa_id', $companyId)->where('activo', 1)->where('deleted_at', null)
            ->orderBy('nombre')->get()->getResultArray();
    }
}
