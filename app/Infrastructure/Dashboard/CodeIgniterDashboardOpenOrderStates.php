<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\DashboardOpenOrderStates;
use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterDashboardOpenOrderStates implements DashboardOpenOrderStates
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function fetch(ActorContext $actor): array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('ordenes.ver')) {
            return [];
        }

        $builder = $this->database->table('ordenes_trabajo')
            ->select('estado, COUNT(*) total')
            ->where('empresa_id', $companyId)
            ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
            ->groupBy('estado');

        if (! $actor->hasAllCompanyBranches()) {
            $branchIds = $actor->branchIds();
            if ($branchIds === []) {
                return [];
            }
            $builder->whereIn('sucursal_id', $branchIds);
        }

        $counts = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $status = (string) ($row['estado'] ?? '');
            if ($status !== '') {
                $counts[$status] = (int) $row['total'];
            }
        }

        $statusOrder = ['BORRADOR', 'EMITIDA', 'EN_PROCESO', 'EN_ESPERA_REPUESTOS', 'ESPERA_REPUESTOS'];
        uksort($counts, static function (string $left, string $right) use ($statusOrder): int {
            $leftOrder = array_search($left, $statusOrder, true);
            $rightOrder = array_search($right, $statusOrder, true);

            return ($leftOrder === false ? PHP_INT_MAX : $leftOrder)
                <=> ($rightOrder === false ? PHP_INT_MAX : $rightOrder)
                ?: strcmp($left, $right);
        });

        return $counts;
    }
}
