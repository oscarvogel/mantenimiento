<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final class ListReadingControl
{
    public function __construct(
        private readonly BaseConnection $database,
    ) {
    }

    /**
     * @return array{
     *     items: list<EquipmentReadingControlRow>,
     *     total: int,
     *     pagination: array{page:int,perPage:int,total:int,totalPages:int}
     * }
     */
    public function execute(ActorContext $actor, ReadingControlQuery $query): array
    {
        $companyId = $actor->companyId();
        $now = new DateTimeImmutable('now');

        // Base query: equipment with active driver assignment where type controls km
        $builder = $this->database->table('equipos e')
            ->select([
                'e.id',
                'e.codigo',
                'e.patente',
                'e.km_actual',
                'te.nombre tipo_nombre',
                'te.controla_km',
                's.id sucursal_id',
                's.nombre sucursal_nombre',
                'a.empleado_id',
                'emp.nombre emp_nombre',
                'emp.apellido emp_apellido',
                'emp.telefono emp_telefono',
            ])
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'inner')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join('employee_equipment_assignments a', 'a.equipo_id = e.id AND a.empresa_id = e.empresa_id AND a.rol = \'CHOFER\' AND a.fecha_hasta IS NULL', 'left')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id AND emp.activo = 1 AND emp.deleted_at IS NULL', 'left')
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('te.controla_km', 1)
            ->where('te.activo', 1)
            ->where('s.estado', 1)
            ->where('s.deleted_at', null);

        // Apply filters
        if ($query->query !== '') {
            $normalized = $this->normalizeSearch($query->query);
            $builder->groupStart()
                ->like('e.codigo', $normalized)
                ->orLike('e.patente', $normalized)
                ->orLike('e.chasis', $normalized)
                ->orLike('te.nombre', $normalized)
                ->orLike('s.nombre', $normalized)
                ->orLike('emp.nombre', $normalized)
                ->orLike('emp.apellido', $normalized)
                ->groupEnd();
        }

        if ($query->branchId !== null) {
            $builder->where('e.sucursal_id', $query->branchId);
        }

        if ($query->typeId !== null) {
            $builder->where('e.tipo_equipo_id', $query->typeId);
        }

        // Get total count for pagination
        $total = (int) $builder->countAllResults(false);

        // Pagination
        $offset = ($query->page - 1) * $query->perPage;
        $rows = $builder->orderBy('e.codigo', 'ASC')
            ->limit($query->perPage, $offset)
            ->get()
            ->getResultArray();

        // Fetch last readings for all equipment in one query
        $equipmentIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $lastReadings = [];
        if ($equipmentIds !== []) {
            $readingRows = $this->database->table('lecturas_equipo')
                ->select('empresa_id, equipo_id, MAX(fecha_lectura) AS ultima_lectura, kilometraje', false)
                ->where('empresa_id', $companyId)
                ->whereIn('equipo_id', $equipmentIds)
                ->where('anulada', 0)
                ->where('kilometraje IS NOT NULL', null, false)
                ->groupBy(['empresa_id', 'equipo_id'])
                ->get()
                ->getResultArray();

            foreach ($readingRows as $r) {
                $lastReadings[(int) $r['equipo_id']] = [
                    'ultima_lectura' => (string) $r['ultima_lectura'],
                    'kilometraje' => (int) $r['kilometraje'],
                ];
            }
        }

        // Fetch last WhatsApp claims for all equipment
        $lastClaims = [];
        if ($equipmentIds !== []) {
            $claimRows = $this->database->table('notificacion_whatsapp_entregas n')
                ->select('n.id, n.equipo_id, n.estado, n.enviada_en, n.instance_id, u.nombre u_nombre, u.apellido u_apellido', false)
                ->join('usuarios u', 'u.id = n.created_by', 'left')
                ->where('n.empresa_id', $companyId)
                ->whereIn('n.equipo_id', $equipmentIds)
                ->where('n.tipo_evento', 'equipo.reclamo_manual_lectura')
                ->where('n.deleted_at', null)
                ->orderBy('n.id', 'DESC')
                ->get()
                ->getResultArray();

            $seen = [];
            foreach ($claimRows as $r) {
                $eqId = (int) $r['equipo_id'];
                if (! isset($seen[$eqId])) {
                    $seen[$eqId] = true;
                    $lastClaims[$eqId] = [
                        'id' => (int) $r['id'],
                        'estado' => (string) $r['estado'],
                        'enviada_en' => $r['enviada_en'] !== null ? (string) $r['enviada_en'] : null,
                        'instance_id' => $r['instance_id'] !== null ? (string) $r['instance_id'] : null,
                        'user_name' => trim((string) ($r['u_nombre'] ?? '') . ' ' . (string) ($r['u_apellido'] ?? '')),
                    ];
                }
            }
        }

        // Build result rows
        $items = [];
        foreach ($rows as $row) {
            $equipmentId = (int) $row['id'];
            $reading = $lastReadings[$equipmentId] ?? null;
            $claim = $lastClaims[$equipmentId] ?? null;

            $lastReadingAt = $reading['ultima_lectura'] ?? null;
            $lastKm = $reading['kilometraje'] ?? null;

            $daysSinceLastReading = 999;
            if ($lastReadingAt !== null) {
                try {
                    $last = new DateTimeImmutable($lastReadingAt);
                    $daysSinceLastReading = max(0, (int) $last->diff($now)->format('%a'));
                } catch (\Throwable) {
                    $daysSinceLastReading = 999;
                }
            }

            $driverName = trim((string) ($row['emp_nombre'] ?? '') . ' ' . (string) ($row['emp_apellido'] ?? ''));
            if ($driverName === '') {
                $driverName = '(sin chofer)';
            }

            $items[] = new EquipmentReadingControlRow(
                equipmentId: $equipmentId,
                equipmentCode: (string) $row['codigo'],
                equipmentPlate: $row['patente'] !== null ? (string) $row['patente'] : null,
                typeName: (string) $row['tipo_nombre'],
                branchId: (int) $row['sucursal_id'],
                branchName: (string) $row['sucursal_nombre'],
                controlsKm: (int) ($row['controla_km'] ?? 0) === 1,
                driverEmployeeId: (int) ($row['empleado_id'] ?? 0),
                driverName: $driverName,
                driverPhone: $row['emp_telefono'] !== null ? (string) $row['emp_telefono'] : null,
                lastKm: $lastKm,
                lastReadingAt: $lastReadingAt,
                daysSinceLastReading: $daysSinceLastReading,
                lastClaimDeliveryId: $claim['id'] ?? null,
                lastClaimAt: $claim['enviada_en'] ?? null,
                lastClaimByUser: $claim['user_name'] !== '' ? $claim['user_name'] : null,
                lastClaimStatus: $claim['estado'] ?? null,
                lastClaimInstanceId: $claim['instance_id'] ?? null,
            );
        }

        // Apply status filter in memory (since it depends on computed daysSinceLastReading)
        $filteredItems = array_filter($items, static fn (EquipmentReadingControlRow $item): bool => $item->filterMatches($query->filter, $now));
        $filteredItems = array_values($filteredItems);

        $totalPages = max(1, (int) ceil(count($filteredItems) / $query->perPage));

        return [
            'items' => $filteredItems,
            'total' => count($filteredItems),
            'pagination' => [
                'page' => $query->page,
                'perPage' => $query->perPage,
                'total' => count($filteredItems),
                'totalPages' => $totalPages,
            ],
        ];
    }

    private function normalizeSearch(string $value): string
    {
        return strtolower(trim($value));
    }
}