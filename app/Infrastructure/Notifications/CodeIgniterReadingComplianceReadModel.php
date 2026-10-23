<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\ReadingComplianceReadModel;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Equipos con lectura de kilometraje vencida.
 *
 * Replica el criterio de `ListReadingControl`: ultima lectura vigente resuelta
 * con un desreferenciado determinista (fecha y kilometraje de la misma fila),
 * chofer vigente unico por equipo y filtro por tipo que controla kilometraje.
 *
 * La ultima lectura puede venir de una fila con horas y sin kilometraje: para
 * el control de lecturas esa fila ya es "lectura registrada" y el equipo deja
 * de figurar como atrasado. Se mantiene la misma regla en los dos lugares para
 * que la pantalla y el informe hablen del mismo universo.
 */
final readonly class CodeIgniterReadingComplianceReadModel implements ReadingComplianceReadModel
{
    private const LAST_READING_SQL = <<<'SQL'
        (
            SELECT le.id lectura_id, le.empresa_id, le.equipo_id, le.fecha_lectura, le.kilometraje
            FROM lecturas_equipo le
            WHERE le.anulada = 0
              AND le.id = (
                  SELECT l2.id
                  FROM lecturas_equipo l2
                  WHERE l2.empresa_id = le.empresa_id
                    AND l2.equipo_id = le.equipo_id
                    AND l2.anulada = 0
                  ORDER BY l2.fecha_lectura DESC, l2.id DESC
                  LIMIT 1
              )
        ) lr
        SQL;

    private const ACTIVE_DRIVER_SQL = <<<'SQL'
        (
            SELECT a.empresa_id, a.equipo_id, a.empleado_id
            FROM employee_equipment_assignments a
            WHERE a.rol = 'CHOFER'
              AND a.fecha_hasta IS NULL
              AND a.id = (
                  SELECT a2.id
                  FROM employee_equipment_assignments a2
                  WHERE a2.empresa_id = a.empresa_id
                    AND a2.equipo_id = a.equipo_id
                    AND a2.rol = 'CHOFER'
                    AND a2.fecha_hasta IS NULL
                  ORDER BY a2.fecha_desde DESC, a2.id DESC
                  LIMIT 1
              )
        ) drv
        SQL;

    public function __construct(private BaseConnection $database)
    {
    }

    public function staleReadings(int $companyId, DateTimeImmutable $cutoff, int $limit = 25): array
    {
        if ($companyId <= 0 || $limit <= 0) {
            return [];
        }

        $rows = $this->database->table('equipos e')
            ->select([
                'e.id',
                'e.codigo',
                'e.patente',
                'emp.nombre emp_nombre',
                'emp.apellido emp_apellido',
                'lr.fecha_lectura ultima_lectura',
                'lr.kilometraje ultima_kilometraje',
            ], false)
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id AND te.activo = 1', 'inner')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join(self::ACTIVE_DRIVER_SQL, 'drv.equipo_id = e.id AND drv.empresa_id = e.empresa_id', 'left', false)
            ->join('empleados emp', 'emp.id = drv.empleado_id AND emp.empresa_id = drv.empresa_id AND emp.activo = 1 AND emp.deleted_at IS NULL', 'left')
            ->join(self::LAST_READING_SQL, 'lr.equipo_id = e.id AND lr.empresa_id = e.empresa_id', 'left', false)
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('te.controla_km', 1)
            ->where('s.estado', 1)
            ->where('s.deleted_at', null)
            ->groupStart()
                ->where('lr.fecha_lectura', null)
                ->orWhere('lr.fecha_lectura <', $cutoff->format('Y-m-d H:i:s'))
            ->groupEnd()
            // Los equipos que nunca registraron lectura van primero: son los
            // que peor estado tienen y el informe se lee de arriba hacia abajo.
            ->orderBy('CASE WHEN lr.fecha_lectura IS NULL THEN 0 ELSE 1 END', 'ASC', false)
            ->orderBy('lr.fecha_lectura', 'ASC', false)
            ->orderBy('e.codigo', 'ASC', false)
            ->limit($limit)
            ->get()
            ->getResultArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'equipmentId' => (int) ($row['id'] ?? 0),
                'code' => trim((string) ($row['codigo'] ?? '')),
                'plate' => trim((string) ($row['patente'] ?? '')),
                'driver' => trim(trim((string) ($row['emp_nombre'] ?? '')) . ' ' . trim((string) ($row['emp_apellido'] ?? ''))),
                'lastReadingAt' => $row['ultima_lectura'] === null ? null : (string) $row['ultima_lectura'],
                'lastKm' => $row['ultima_kilometraje'] === null ? null : (int) $row['ultima_kilometraje'],
            ];
        }

        return $items;
    }
}
