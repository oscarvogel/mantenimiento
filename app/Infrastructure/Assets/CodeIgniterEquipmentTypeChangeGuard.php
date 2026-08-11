<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentTypeChangeGuard;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentTypeChangeGuard implements EquipmentTypeChangeGuard
{
    public function __construct(private readonly BaseConnection $database) {}

    public function hasOpenWorkOrders(int $companyId, int $equipmentId): bool
    {
        return $this->database->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
            ->countAllResults() > 0;
    }

    public function hasActivePlanUsingKilometers(int $companyId, int $equipmentId): bool
    {
        return $this->hasActivePlanWithCriterion($companyId, $equipmentId, 'intervalo_km');
    }

    public function hasActivePlanUsingHours(int $companyId, int $equipmentId): bool
    {
        return $this->hasActivePlanWithCriterion($companyId, $equipmentId, 'intervalo_horas');
    }

    private function hasActivePlanWithCriterion(int $companyId, int $equipmentId, string $criterion): bool
    {
        return $this->database->table('planes_mantenimiento')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->where($criterion . ' IS NOT NULL', null, false)
            ->countAllResults() > 0;
    }
}
