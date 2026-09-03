<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkRequests;

use App\Application\WorkRequests\Port\WorkRequestRepository;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterWorkRequestRepository implements WorkRequestRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function createScoped(
        int $companyId,
        int $equipmentId,
        ?array $branchIds,
        int $userId,
        string $description,
        string $reportedAt,
    ): ?int {
        if ($branchIds === []) {
            return null;
        }

        $scope = $this->database->table('equipos')
            ->select('id, sucursal_id')
            ->where('empresa_id', $companyId)
            ->where('id', $equipmentId)
            ->where('deleted_at', null);
        if ($branchIds !== null) {
            $scope->whereIn('sucursal_id', $branchIds);
        }
        $equipment = $scope->get()->getRowArray();
        if ($equipment === null) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table('solicitudes_mantenimiento')->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => (int) $equipment['sucursal_id'],
            'equipo_id' => $equipmentId,
            'reportado_por' => $userId,
            'fecha_reporte' => $reportedAt,
            'descripcion' => $description,
            'estado' => 'PENDIENTE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->database->insertID();
    }
}
