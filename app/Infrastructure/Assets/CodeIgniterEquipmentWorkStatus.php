<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentWorkStatus;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentWorkStatus implements EquipmentWorkStatus
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function hasOpenOrders(int $companyId, int $equipmentId): bool
    {
        return $this->database->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
            ->countAllResults() > 0;
    }
}
