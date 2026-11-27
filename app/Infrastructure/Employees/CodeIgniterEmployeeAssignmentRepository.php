<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

use App\Application\Employees\ClosedDriverAssignment;
use App\Application\Employees\Port\EmployeeAssignmentRepository;
use App\Domain\Employees\EmployeeEquipmentAssignment;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;
use RuntimeException;

final class CodeIgniterEmployeeAssignmentRepository implements EmployeeAssignmentRepository
{
    public function __construct(private readonly BaseConnection $database) {}

    public function currentForEmployee(int $companyId, int $employeeId): ?EmployeeEquipmentAssignment
    {
        $row = $this->database->table('employee_equipment_assignments')
            ->where('empresa_id', $companyId)
            ->where('empleado_id', $employeeId)
            ->where('fecha_hasta', null)
            ->get()->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    public function currentDriverForEquipment(int $companyId, int $equipmentId): ?EmployeeEquipmentAssignment
    {
        $row = $this->database->table('employee_equipment_assignments')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('rol', EmployeeEquipmentAssignment::ROLE_DRIVER)
            ->where('fecha_hasta', null)
            ->get()->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    public function historyForEmployee(int $companyId, int $employeeId): array
    {
        return $this->database->table('employee_equipment_assignments a')
            ->select('a.*, e.codigo equipo_codigo, e.patente equipo_patente')
            ->join('equipos e', 'e.id = a.equipo_id')
            ->where('a.empresa_id', $companyId)
            ->where('a.empleado_id', $employeeId)
            ->orderBy('a.fecha_desde', 'DESC')
            ->get()->getResultArray();
    }

    public function historyForEquipment(int $companyId, int $equipmentId): array
    {
        return $this->database->table('employee_equipment_assignments a')
            ->select('a.*, emp.nombre empleado_nombre, emp.apellido empleado_apellido')
            ->join('empleados emp', 'emp.id = a.empleado_id')
            ->where('a.empresa_id', $companyId)
            ->where('a.equipo_id', $equipmentId)
            ->orderBy('a.fecha_desde', 'DESC')
            ->get()->getResultArray();
    }

    public function assignmentHistory(
        int $companyId,
        ?int $employeeId = null,
        ?string $equipmentSearch = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?bool $current = null,
    ): array {
        $builder = $this->database->table('employee_equipment_assignments a')
            ->select('a.id, a.empleado_id, a.equipo_id, a.rol, a.fecha_desde, a.fecha_hasta, a.observaciones, emp.nombre empleado_nombre, emp.apellido empleado_apellido, emp.activo empleado_activo, emp.foto_path empleado_foto_path, e.codigo equipo_codigo, e.patente equipo_patente, s.nombre sucursal_nombre')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id')
            ->join('sucursales s', 's.id = e.sucursal_id', 'left')
            ->where('a.empresa_id', $companyId)
            ->where('a.rol', EmployeeEquipmentAssignment::ROLE_DRIVER);

        if ($employeeId !== null && $employeeId > 0) {
            $builder->where('a.empleado_id', $employeeId);
        }

        $equipmentSearch = trim((string) $equipmentSearch);
        if ($equipmentSearch !== '') {
            $builder->groupStart()
                ->like('e.codigo', $equipmentSearch)
                ->orLike('e.patente', $equipmentSearch)
                ->groupEnd();
        }

        if ($from !== null) {
            $builder->groupStart()
                ->where('a.fecha_hasta >=', $from->format('Y-m-d'))
                ->orWhere('a.fecha_hasta', null)
                ->groupEnd();
        }

        if ($to !== null) {
            $builder->where('a.fecha_desde <=', $to->format('Y-m-d'));
        }

        if ($current === true) {
            $builder->where('a.fecha_hasta', null);
        } elseif ($current === false) {
            $builder->where('a.fecha_hasta !=', null);
        }

        return $builder
            ->orderBy('a.fecha_desde', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit(500)
            ->get()
            ->getResultArray();
    }

    public function assignDriver(
        int $companyId,
        int $employeeId,
        int $equipmentId,
        DateTimeImmutable $startsAt,
        ?string $notes,
        int $actorUserId,
    ): int {
        $this->database->transStart();

        $employee = $this->database->query(
            'SELECT id, activo FROM empleados WHERE empresa_id = ? AND id = ? AND deleted_at IS NULL' . $this->lockSuffix(),
            [$companyId, $employeeId],
        )->getRowArray();
        if ($employee === null || ! (bool) $employee['activo']) {
            $this->database->transRollback();
            throw new DomainException('El empleado no existe o está dado de baja.');
        }

        $equipment = $this->database->query(
            'SELECT id FROM equipos WHERE empresa_id = ? AND id = ? AND deleted_at IS NULL' . $this->lockSuffix(),
            [$companyId, $equipmentId],
        )->getRowArray();
        if ($equipment === null) {
            $this->database->transRollback();
            throw new DomainException('El móvil no existe en la empresa.');
        }

        $existingForEmployee = $this->database->query(
            'SELECT id, equipo_id, rol FROM employee_equipment_assignments WHERE empresa_id = ? AND empleado_id = ? AND fecha_hasta IS NULL' . $this->lockSuffix(),
            [$companyId, $employeeId],
        )->getRowArray();

        if ($existingForEmployee !== null
            && (int) $existingForEmployee['equipo_id'] === $equipmentId
            && (string) $existingForEmployee['rol'] === EmployeeEquipmentAssignment::ROLE_DRIVER) {
            $this->database->transComplete();
            return (int) $existingForEmployee['id'];
        }

        $date = $startsAt->format('Y-m-d');
        $now = date('Y-m-d H:i:s');

        if ($existingForEmployee !== null) {
            $this->database->table('employee_equipment_assignments')
                ->where('id', (int) $existingForEmployee['id'])
                ->where('empresa_id', $companyId)
                ->update([
                    'fecha_hasta' => $date,
                    'updated_at' => $now,
                    'updated_by' => $actorUserId,
                ]);
        }

        $currentDriver = $this->database->query(
            'SELECT id, empleado_id FROM employee_equipment_assignments WHERE empresa_id = ? AND equipo_id = ? AND rol = ? AND fecha_hasta IS NULL' . $this->lockSuffix(),
            [$companyId, $equipmentId, EmployeeEquipmentAssignment::ROLE_DRIVER],
        )->getRowArray();

        if ($currentDriver !== null) {
            $this->database->table('employee_equipment_assignments')
                ->where('id', (int) $currentDriver['id'])
                ->where('empresa_id', $companyId)
                ->update([
                    'fecha_hasta' => $date,
                    'updated_at' => $now,
                    'updated_by' => $actorUserId,
                ]);
        }

        $assignment = EmployeeEquipmentAssignment::create(
            $companyId,
            $employeeId,
            $equipmentId,
            EmployeeEquipmentAssignment::ROLE_DRIVER,
            $startsAt,
            $notes,
        );

        $this->database->table('employee_equipment_assignments')->insert([
            'empresa_id' => $assignment->companyId(),
            'empleado_id' => $assignment->employeeId(),
            'equipo_id' => $assignment->equipmentId(),
            'rol' => $assignment->role(),
            'fecha_desde' => $assignment->startsAt()->format('Y-m-d'),
            'fecha_hasta' => null,
            'observaciones' => $assignment->notes(),
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        $id = (int) $this->database->insertID();
        $this->database->transComplete();

        if (! $this->database->transStatus() || $id <= 0) {
            throw new RuntimeException('No se pudo asignar el chofer al móvil.');
        }

        return $id;
    }

    public function closeCurrentForEmployee(
        int $companyId,
        int $employeeId,
        DateTimeImmutable $endsAt,
        int $actorUserId,
        ?string $reason = null,
    ): void {
        $payload = [
            'fecha_hasta' => $endsAt->format('Y-m-d'),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $actorUserId,
        ];
        if ($reason !== null && trim($reason) !== '' && $this->hasClosureColumns()) {
            $payload['motivo_cierre'] = mb_substr(trim($reason), 0, 255);
        }

        $this->database->table('employee_equipment_assignments')
            ->where('empresa_id', $companyId)
            ->where('empleado_id', $employeeId)
            ->where('fecha_hasta', null)
            ->update($payload);
    }

    public function closeCurrentForEquipment(
        int $companyId,
        int $equipmentId,
        DateTimeImmutable $endsAt,
        string $reason,
        ?string $notes,
        int $actorUserId,
    ): ?ClosedDriverAssignment {
        $equipment = $this->database->query(
            'SELECT id, codigo, patente, sucursal_id FROM equipos '
            . 'WHERE empresa_id = ? AND id = ? AND deleted_at IS NULL' . $this->lockSuffix(),
            [$companyId, $equipmentId],
        )->getRowArray();
        if ($equipment === null) {
            throw new DomainException('El equipo no existe en la empresa.');
        }

        $closureSelect = $this->hasClosureColumns()
            ? ', a.motivo_cierre, a.observaciones_cierre'
            : ', NULL motivo_cierre, NULL observaciones_cierre';

        $row = $this->database->query(
            "SELECT a.id, a.empresa_id, a.equipo_id, a.empleado_id, a.rol, a.fecha_desde, a.fecha_hasta, "
            . "a.observaciones" . $closureSelect . ', '
            . 'emp.nombre empleado_nombre, emp.apellido empleado_apellido '
            . 'FROM employee_equipment_assignments a '
            . 'INNER JOIN empleados emp ON emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id '
            . "WHERE a.empresa_id = ? AND a.equipo_id = ? AND a.rol = 'CHOFER' AND a.fecha_hasta IS NULL "
            . 'ORDER BY a.id DESC LIMIT 1' . $this->lockSuffix(),
            [$companyId, $equipmentId],
        )->getRowArray();
        if ($row === null) {
            return null;
        }

        $assignment = EmployeeEquipmentAssignment::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['empleado_id'],
            (int) $row['equipo_id'],
            (string) $row['rol'],
            new DateTimeImmutable((string) $row['fecha_desde']),
            empty($row['fecha_hasta']) ? null : new DateTimeImmutable((string) $row['fecha_hasta']),
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            $row['motivo_cierre'] === null ? null : (string) $row['motivo_cierre'],
            $row['observaciones_cierre'] === null ? null : (string) $row['observaciones_cierre'],
        );
        $assignment->close($endsAt, $reason, $notes);

        $effectiveDate = $assignment->endsAt();
        if ($effectiveDate === null) {
            throw new RuntimeException('No se pudo determinar la fecha de cierre de la asignación.');
        }

        $payload = [
            'fecha_hasta' => $effectiveDate->format('Y-m-d'),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $actorUserId,
        ];
        if ($this->hasClosureColumns()) {
            $payload['motivo_cierre'] = $assignment->closureReason();
            $payload['observaciones_cierre'] = $assignment->closureNotes();
        }

        $this->database->table('employee_equipment_assignments')
            ->where('id', (int) $row['id'])
            ->where('empresa_id', $companyId)
            ->where('fecha_hasta', null)
            ->update($payload);

        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('La asignación cambió mientras se desvinculaba al chofer. Volvé a intentar.');
        }

        return new ClosedDriverAssignment(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['equipo_id'],
            (int) $row['empleado_id'],
            (string) ($equipment['codigo'] ?? ''),
            (string) ($equipment['patente'] ?? ''),
            isset($equipment['sucursal_id']) ? (int) $equipment['sucursal_id'] : null,
            trim((string) ($row['empleado_nombre'] ?? '') . ' ' . (string) ($row['empleado_apellido'] ?? '')),
            $assignment->startsAt(),
            $effectiveDate,
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            (string) $assignment->closureReason(),
            $assignment->closureNotes(),
        );
    }

    private function hasClosureColumns(): bool
    {
        return $this->database->fieldExists('motivo_cierre', 'employee_equipment_assignments')
            && $this->database->fieldExists('observaciones_cierre', 'employee_equipment_assignments');
    }

    private function lockSuffix(): string
    {
        return $this->database->DBDriver === 'SQLite3' ? '' : ' FOR UPDATE';
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): EmployeeEquipmentAssignment
    {
        return EmployeeEquipmentAssignment::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['empleado_id'],
            (int) $row['equipo_id'],
            (string) $row['rol'],
            new DateTimeImmutable((string) $row['fecha_desde']),
            empty($row['fecha_hasta']) ? null : new DateTimeImmutable((string) $row['fecha_hasta']),
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
        );
    }
}
