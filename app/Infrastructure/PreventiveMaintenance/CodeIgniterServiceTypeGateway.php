<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterServiceTypeGateway implements ServiceTypeGateway
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function findActiveDefinition(int $companyId, int $serviceTypeId): ?array
    {
        $builder = $this->db->table('tipos_servicio')
            ->select('id, intervalo_km, intervalo_horas, intervalo_dias, anticipacion_km, anticipacion_horas, anticipacion_dias, prioridad')
            ->where('id', $serviceTypeId)
            ->where('activo', 1);

        if ($this->db->fieldExists('empresa_id', 'tipos_servicio')) {
            $builder->groupStart()
                ->where('empresa_id', $companyId)
                ->orWhere('empresa_id', null)
                ->groupEnd();
        }

        $row = $builder->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'intervalKm' => $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
            'intervalHoursTenths' => DecimalHours::toTenths($row['intervalo_horas']),
            'intervalDays' => $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
            'warningKm' => $row['anticipacion_km'] === null ? null : (int) $row['anticipacion_km'],
            'warningHoursTenths' => DecimalHours::toTenths($row['anticipacion_horas']),
            'warningDays' => $row['anticipacion_dias'] === null ? null : (int) $row['anticipacion_dias'],
            'priority' => (string) ($row['prioridad'] ?: 'MEDIA'),
        ];
    }
}
