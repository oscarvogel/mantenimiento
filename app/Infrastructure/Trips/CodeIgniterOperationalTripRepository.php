<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\TripConflict;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripStop;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterOperationalTripRepository implements OperationalTripRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function nextNumber(int $companyId): int
    {
        $table = $this->database->prefixTable('viajes_operativos_numeracion');
        $now = date('Y-m-d H:i:s');
        $insert = $this->database->DBDriver === 'SQLite3' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->database->query(
            $insert . ' INTO ' . $table . ' (empresa_id, ultimo_numero, updated_at) VALUES (?, 0, ?)',
            [$companyId, $now],
        );
        $lock = $this->database->DBDriver === 'SQLite3' ? '' : ' FOR UPDATE';
        $row = $this->database->query(
            'SELECT ultimo_numero FROM ' . $table . ' WHERE empresa_id = ?' . $lock,
            [$companyId],
        )->getRowArray();
        if ($row === null) {
            throw new RuntimeException('No se pudo reservar el número de viaje.');
        }

        $next = (int) $row['ultimo_numero'] + 1;
        $this->database->table('viajes_operativos_numeracion')
            ->where('empresa_id', $companyId)
            ->update(['ultimo_numero' => $next, 'updated_at' => $now]);

        return $next;
    }

    public function add(Trip $trip, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        $inserted = $this->database->table('viajes_operativos')->insert([
            'empresa_id' => $trip->companyId(),
            'sucursal_id' => $trip->branchId(),
            'numero' => $trip->number(),
            'estado' => $trip->status(),
            'fecha_salida_planificada' => $trip->plannedDeparture()->format('Y-m-d H:i:s'),
            'fecha_regreso_planificada' => $trip->plannedReturn()->format('Y-m-d H:i:s'),
            'fecha_salida_real' => $trip->actualDeparture()?->format('Y-m-d H:i:s'),
            'fecha_regreso_real' => $trip->actualReturn()?->format('Y-m-d H:i:s'),
            'km_salida' => $trip->departureKm(),
            'km_regreso' => $trip->returnKm(),
            'observaciones' => $trip->notes() !== '' ? $trip->notes() : null,
            'motivo_cancelacion' => $trip->cancellationReason(),
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);
        if (! $inserted) {
            throw new RuntimeException('No se pudo crear el viaje.');
        }

        $tripId = (int) $this->database->insertID();
        if ($tripId <= 0) {
            throw new RuntimeException('No se pudo crear el viaje.');
        }

        $this->insertAssignments($tripId, $trip, $now);
        $this->insertStops($tripId, $trip, $now);

        return $tripId;
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $tripId): ?Trip
    {
        $sql = 'SELECT * FROM ' . $this->database->prefixTable('viajes_operativos')
            . ' WHERE empresa_id = ? AND id = ?';
        $binds = [$companyId, $tripId];
        if ($branchIds !== null) {
            $sql .= ' AND sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
            $binds = [...$binds, ...$branchIds];
        }
        if ($this->database->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->database->query($sql, $binds)->getRowArray();
        if ($row === null) {
            return null;
        }

        $assignments = $this->database->table('viaje_operativo_asignaciones')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        $stops = $this->database->table('viaje_operativo_paradas')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->orderBy('orden', 'ASC')
            ->get()->getResultArray();

        return $this->hydrate($row, $assignments, $stops);
    }

    public function save(Trip $trip, int $actorUserId): void
    {
        $tripId = $trip->id();
        if ($tripId === null) {
            throw new RuntimeException('No se puede actualizar un viaje sin identidad.');
        }

        $this->database->table('viajes_operativos')
            ->where('empresa_id', $trip->companyId())
            ->where('id', $tripId)
            ->update([
                'estado' => $trip->status(),
                'fecha_salida_real' => $trip->actualDeparture()?->format('Y-m-d H:i:s'),
                'fecha_regreso_real' => $trip->actualReturn()?->format('Y-m-d H:i:s'),
                'km_salida' => $trip->departureKm(),
                'km_regreso' => $trip->returnKm(),
                'observaciones' => $trip->notes() !== '' ? $trip->notes() : null,
                'motivo_cancelacion' => $trip->cancellationReason(),
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);
    }

    public function conflicts(
        int $companyId,
        array $equipmentIds,
        array $employeeIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $excludeTripId = null,
    ): array {
        if ($equipmentIds === [] && $employeeIds === []) {
            return [];
        }

        $sql = 'SELECT DISTINCT v.id, v.numero, v.estado, a.rol, a.equipo_id, a.empleado_id, '
            . 'COALESCE(v.fecha_salida_real, v.fecha_salida_planificada) AS inicio, '
            . 'COALESCE(v.fecha_regreso_real, v.fecha_regreso_planificada) AS fin '
            . 'FROM ' . $this->database->prefixTable('viajes_operativos') . ' v '
            . 'JOIN ' . $this->database->prefixTable('viaje_operativo_asignaciones') . ' a '
            . 'ON a.viaje_id = v.id AND a.empresa_id = v.empresa_id '
            . 'WHERE v.empresa_id = ? AND v.estado IN (?, ?) '
            . 'AND COALESCE(v.fecha_salida_real, v.fecha_salida_planificada) < ? '
            . 'AND COALESCE(v.fecha_regreso_real, v.fecha_regreso_planificada) > ?';
        $binds = [
            $companyId,
            Trip::PLANIFICADO,
            Trip::EN_CURSO,
            $to->format('Y-m-d H:i:s'),
            $from->format('Y-m-d H:i:s'),
        ];
        if ($excludeTripId !== null) {
            $sql .= ' AND v.id <> ?';
            $binds[] = $excludeTripId;
        }

        $conditions = [];
        if ($equipmentIds !== []) {
            $conditions[] = 'a.equipo_id IN (' . implode(',', array_fill(0, count($equipmentIds), '?')) . ')';
            $binds = [...$binds, ...$equipmentIds];
        }
        if ($employeeIds !== []) {
            $conditions[] = 'a.empleado_id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            $binds = [...$binds, ...$employeeIds];
        }
        $sql .= ' AND (' . implode(' OR ', $conditions) . ') ORDER BY inicio ASC';

        $rows = $this->database->query($sql, $binds)->getResultArray();
        $conflicts = [];
        foreach ($rows as $row) {
            $role = (string) $row['rol'];
            $referenceId = $role === TripAssignment::CHOFER ? (int) $row['empleado_id'] : (int) $row['equipo_id'];
            $conflicts[] = new TripConflict(
                (int) $row['id'],
                (int) $row['numero'],
                (string) $row['estado'],
                $role,
                $referenceId,
                new DateTimeImmutable((string) $row['inicio']),
                new DateTimeImmutable((string) $row['fin']),
            );
        }

        return $conflicts;
    }

    private function insertAssignments(int $tripId, Trip $trip, string $now): void
    {
        $rows = [];
        foreach ($trip->assignments() as $assignment) {
            $rows[] = [
                'viaje_id' => $tripId,
                'empresa_id' => $trip->companyId(),
                'sucursal_id' => $trip->branchId(),
                'rol' => $assignment->role(),
                'equipo_id' => $assignment->isEquipment() ? $assignment->referenceId() : null,
                'empleado_id' => $assignment->role() === TripAssignment::CHOFER ? $assignment->referenceId() : null,
                'desde' => $assignment->from()->format('Y-m-d H:i:s'),
                'hasta' => $assignment->to()?->format('Y-m-d H:i:s'),
                'motivo' => $assignment->reason(),
                'porcentaje_liquidacion' => $assignment->liquidationPercentage(),
                'created_at' => $now,
            ];
        }
        if ($rows !== [] && ! $this->database->table('viaje_operativo_asignaciones')->insertBatch($rows)) {
            throw new RuntimeException('No se pudieron guardar las asignaciones del viaje.');
        }
    }

    private function insertStops(int $tripId, Trip $trip, string $now): void
    {
        $rows = [];
        foreach ($trip->stops() as $stop) {
            $rows[] = [
                'viaje_id' => $tripId,
                'empresa_id' => $trip->companyId(),
                'sucursal_id' => $trip->branchId(),
                'orden' => $stop->order(),
                'tipo' => $stop->type(),
                'cliente_id' => $stop->customerId(),
                'domicilio_id' => $stop->addressId(),
                'ubicacion' => $stop->location(),
                'fecha_estimada' => $stop->estimatedAt()?->format('Y-m-d H:i:s'),
                'fecha_real' => null,
                'descripcion_carga' => $stop->cargoDescription(),
                'cantidad' => $stop->quantity(),
                'unidad_carga_id' => $stop->loadUnitId(),
                'observaciones' => $stop->notes(),
                'created_at' => $now,
            ];
        }
        if ($rows !== [] && ! $this->database->table('viaje_operativo_paradas')->insertBatch($rows)) {
            throw new RuntimeException('No se pudieron guardar las paradas del viaje.');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $assignments
     * @param list<array<string,mixed>> $stops
     */
    private function hydrate(array $row, array $assignments, array $stops): Trip
    {
        $assignmentObjects = [];
        foreach ($assignments as $assignment) {
            $role = (string) $assignment['rol'];
            $assignmentObjects[] = new TripAssignment(
                $role,
                $role === TripAssignment::CHOFER ? (int) $assignment['empleado_id'] : (int) $assignment['equipo_id'],
                new DateTimeImmutable((string) $assignment['desde']),
                empty($assignment['hasta']) ? null : new DateTimeImmutable((string) $assignment['hasta']),
                $assignment['motivo'] === null ? null : (string) $assignment['motivo'],
                isset($assignment['porcentaje_liquidacion']) && $assignment['porcentaje_liquidacion'] !== null
                    ? (float) $assignment['porcentaje_liquidacion']
                    : null,
            );
        }

        $stopObjects = [];
        foreach ($stops as $stop) {
            $stopObjects[] = new TripStop(
                (int) $stop['orden'],
                (string) $stop['tipo'],
                (string) $stop['ubicacion'],
                $stop['cliente_id'] === null ? null : (int) $stop['cliente_id'],
                $stop['domicilio_id'] === null ? null : (int) $stop['domicilio_id'],
                empty($stop['fecha_estimada']) ? null : new DateTimeImmutable((string) $stop['fecha_estimada']),
                $stop['descripcion_carga'] === null ? null : (string) $stop['descripcion_carga'],
                $stop['cantidad'] === null ? null : (string) $stop['cantidad'],
                $stop['unidad_carga_id'] === null ? null : (int) $stop['unidad_carga_id'],
                $stop['observaciones'] === null ? null : (string) $stop['observaciones'],
            );
        }

        return Trip::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['sucursal_id'],
            (int) $row['numero'],
            (string) $row['estado'],
            new DateTimeImmutable((string) $row['fecha_salida_planificada']),
            new DateTimeImmutable((string) $row['fecha_regreso_planificada']),
            empty($row['fecha_salida_real']) ? null : new DateTimeImmutable((string) $row['fecha_salida_real']),
            empty($row['fecha_regreso_real']) ? null : new DateTimeImmutable((string) $row['fecha_regreso_real']),
            $row['km_salida'] === null ? null : (int) $row['km_salida'],
            $row['km_regreso'] === null ? null : (int) $row['km_regreso'],
            $assignmentObjects,
            $stopObjects,
            (string) ($row['observaciones'] ?? ''),
            $row['motivo_cancelacion'] === null ? null : (string) $row['motivo_cancelacion'],
        );
    }
}
