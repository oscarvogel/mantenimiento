<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\EquipmentForPlan;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Domain\PreventiveMaintenance\UsoActual;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterPreventiveAssetGateway implements PreventiveAssetGateway
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?EquipmentForPlan
    {
        $builder = $this->db->table('equipos e')
            ->select('e.id, e.empresa_id, e.sucursal_id, e.estado, e.km_actual, e.horas_actuales, te.controla_km, te.controla_horas')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->where('e.id', $equipmentId)
            ->where('e.empresa_id', $companyId)
            ->where('e.deleted_at', null)
            ->where('te.activo', 1);

        $this->applyBranchScope($builder, $branchIds);
        $row = $builder->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        return new EquipmentForPlan(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['sucursal_id'],
            (string) $row['estado'] === 'ACTIVO',
            (bool) $row['controla_km'],
            (bool) $row['controla_horas'],
            new UsoActual(
                $row['km_actual'] === null ? null : (int) $row['km_actual'],
                DecimalHours::toTenths($row['horas_actuales']),
            ),
        );
    }

    /** @param list<int>|null $branchIds */
    private function applyBranchScope(object $builder, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);
            return;
        }

        $builder->whereIn('e.sucursal_id', $branchIds);
    }
}
