<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripFleetLookup;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripFleetLookup implements TripFleetLookup
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function equipment(int $companyId, int $equipmentId): ?array
    {
        $row = $this->database->table('equipos e')
            ->select('e.id, e.sucursal_id, e.codigo, e.patente, e.estado, t.nombre AS tipo_nombre')
            ->join('tipos_equipo t', 't.id = e.tipo_equipo_id')
            ->where('e.empresa_id', $companyId)
            ->where('e.id', $equipmentId)
            ->where('e.deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        $type = strtr(mb_strtolower((string) $row['tipo_nombre']), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
        $trailer = preg_match('/acoplado|semirremolque|remolque|batea/', $type) === 1;
        $tractor = preg_match('/camion|tractor|chasis|cabina|utilitario|pickup/', $type) === 1;

        return [
            'id' => (int) $row['id'],
            'branchId' => (int) $row['sucursal_id'],
            'label' => $this->equipmentLabel($row),
            'trailer' => $trailer,
            'tractor' => $tractor,
        ];
    }

    public function driver(int $companyId, int $employeeId): ?array
    {
        $row = $this->database->table('empleados')
            ->select('id, nombre, apellido, activo')
            ->where('empresa_id', $companyId)
            ->where('id', $employeeId)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'label' => trim((string) $row['nombre'] . ' ' . (string) ($row['apellido'] ?? '')),
            'active' => (bool) $row['activo'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function equipmentLabel(array $row): string
    {
        $plate = trim((string) ($row['patente'] ?? ''));
        $code = trim((string) ($row['codigo'] ?? ''));

        return $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code;
    }
}
