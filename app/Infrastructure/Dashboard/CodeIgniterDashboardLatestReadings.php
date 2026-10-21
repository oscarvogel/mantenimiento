<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\DashboardLatestReadings;
use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterDashboardLatestReadings implements DashboardLatestReadings
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function byEquipment(ActorContext $actor): array
    {
        $companyId = $actor->companyId();
        if ($companyId === null) {
            return [];
        }

        $builder = $this->database->table('lecturas_equipo le')
            ->select('le.equipo_id, le.fecha_lectura')
            ->join('equipos e', 'e.id = le.equipo_id AND e.empresa_id = le.empresa_id', 'inner')
            ->where('le.empresa_id', $companyId)
            ->where('e.deleted_at', null)
            ->where('le.anulada', 0)
            ->where(
                'le.id = ('
                . 'SELECT l2.id FROM lecturas_equipo l2 '
                . 'WHERE l2.empresa_id = le.empresa_id AND l2.equipo_id = le.equipo_id AND l2.anulada = 0 '
                . 'ORDER BY l2.fecha_lectura DESC, l2.id DESC LIMIT 1)',
                null,
                false,
            );

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds !== null) {
            if ($branchIds === []) {
                return [];
            }

            $builder->whereIn('e.sucursal_id', $branchIds);
        }

        $latest = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $latest[(int) $row['equipo_id']] = (string) $row['fecha_lectura'];
        }

        return $latest;
    }
}
