<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentRelationStatus;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentRelationStatus implements EquipmentRelationStatus
{
    public function __construct(private readonly BaseConnection $database) {}

    public function hasActiveRelations(int $companyId, int $equipmentId): bool
    {
        return $this->database->table('equipo_relaciones')
            ->where('empresa_id', $companyId)
            ->where('hasta', null)
            ->groupStart()->where('equipo_principal_id', $equipmentId)->orWhere('equipo_relacionado_id', $equipmentId)->groupEnd()
            ->countAllResults() > 0;
    }
}
