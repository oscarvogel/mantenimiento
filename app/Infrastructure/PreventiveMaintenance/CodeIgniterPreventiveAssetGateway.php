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
            ->select('e.id, e.empresa_id, e.sucursal_id, e.tipo_equipo_id, e.estado, e.km_actual, e.horas_actuales, te.controla_km, te.controla_horas, ma.nombre marca_nombre, mo.nombre modelo_nombre')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('marcas ma', 'ma.id = e.marca_id AND ma.empresa_id = e.empresa_id', 'left')
            ->join('modelos mo', 'mo.id = e.modelo_id AND mo.empresa_id = e.empresa_id', 'left')
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
                (int) $row['tipo_equipo_id'],
                $row['marca_nombre'] === null ? null : (string) $row['marca_nombre'],
                $row['modelo_nombre'] === null ? null : (string) $row['modelo_nombre'],
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
