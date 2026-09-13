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

    public function listScoped(int $companyId, ?array $branchIds, array $filters, int $page, int $perPage, ?int $reportedBy): array
    {
        $base = $this->database->table('solicitudes_mantenimiento s')
            ->select('s.id, s.estado, s.prioridad, s.fecha_reporte, s.descripcion, s.motivo_resolucion, s.agrupada_en_id, e.codigo equipo_codigo, e.patente equipo_patente, suc.nombre sucursal_nombre, u.nombre reportado_por_nombre')
            ->join('equipos e', 'e.id = s.equipo_id AND e.empresa_id = s.empresa_id', 'inner')
            ->join('sucursales suc', 'suc.id = s.sucursal_id AND suc.empresa_id = s.empresa_id', 'inner')
            ->join('usuarios u', 'u.id = s.reportado_por', 'inner')
            ->where('s.empresa_id', $companyId);
        $this->applyScope($base, $branchIds, $reportedBy);
        $this->applyFilters($base, $filters);

        $total = (int) $base->countAllResults(false);
        $rows = $base->orderBy("FIELD(s.estado, 'PENDIENTE', 'POSTERGADA', 'APROBADA', 'AGRUPADA', 'RECHAZADA')", '', false)
            ->orderBy('s.fecha_reporte', 'DESC')
            ->get($perPage, ($page - 1) * $perPage)->getResultArray();

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'status' => (string) $row['estado'],
                'priority' => (string) ($row['prioridad'] ?? 'MEDIA'),
                'reportedAt' => (string) $row['fecha_reporte'],
                'description' => (string) $row['descripcion'],
                'resolutionReason' => $row['motivo_resolucion'] ?: null,
                'groupRequestId' => $row['agrupada_en_id'] === null ? null : (int) $row['agrupada_en_id'],
                'equipmentCode' => (string) $row['equipo_codigo'],
                'plate' => $row['equipo_patente'] ?: null,
                'branchName' => (string) $row['sucursal_nombre'],
                'reportedBy' => (string) $row['reportado_por_nombre'],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function reviewScoped(int $companyId, ?array $branchIds, int $requestId, string $status, ?string $reason, int $reviewedBy, ?int $groupRequestId): bool
    {
        $query = $this->database->table('solicitudes_mantenimiento s')
            ->select('s.id, s.sucursal_id')
            ->where('s.id', $requestId)
            ->where('s.empresa_id', $companyId)
            ->whereIn('s.estado', ['PENDIENTE', 'POSTERGADA']);
        $this->applyScope($query, $branchIds, null);
        $request = $query->get()->getRowArray();
        if ($request === null) return false;

        if ($groupRequestId !== null) {
            $groupQuery = $this->database->table('solicitudes_mantenimiento')
                ->where('id', $groupRequestId)
                ->where('empresa_id', $companyId)
                ->where('sucursal_id', (int) $request['sucursal_id'])
                ->whereIn('estado', ['PENDIENTE', 'APROBADA']);
            if ($groupQuery->countAllResults() !== 1) return false;
        }

        $now = date('Y-m-d H:i:s');
        return $this->database->table('solicitudes_mantenimiento')
            ->where('id', $requestId)
            ->where('empresa_id', $companyId)
            ->update([
                'estado' => $status,
                'motivo_resolucion' => $reason,
                'revisado_por' => $reviewedBy,
                'revisado_at' => $now,
                'agrupada_en_id' => $groupRequestId,
                'updated_at' => $now,
            ]);
    }

    private function applyScope(object $query, ?array $branchIds, ?int $reportedBy): void
    {
        if ($branchIds === []) $query->where('1 = 0', null, false);
        elseif ($branchIds !== null) $query->whereIn('s.sucursal_id', $branchIds);
        if ($reportedBy !== null) $query->where('s.reportado_por', $reportedBy);
    }

    private function applyFilters(object $query, array $filters): void
    {
        if (($filters['status'] ?? '') !== '') $query->where('s.estado', (string) $filters['status']);
        if (($filters['branch_id'] ?? '') !== '') $query->where('s.sucursal_id', (int) $filters['branch_id']);
        if (($filters['q'] ?? '') !== '') {
            $term = (string) $filters['q'];
            $query->groupStart()->like('s.descripcion', $term)->orLike('e.codigo', $term)->orLike('e.patente', $term)->groupEnd();
        }
    }
}
