<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

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
            ->select('a.id, a.empleado_id, a.equipo_id, a.rol, a.fecha_desde, a.fecha_hasta, a.observaciones, emp.nombre empleado_nombre, emp.apellido empleado_apellido, emp.activo empleado_activo, e.codigo equipo_codigo, e.patente equipo_patente, s.nombre sucursal_nombre')
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
            'SELECT id, activo FROM empleados WHERE empresa_id = ? AND id = ? AND deleted_at IS NULL FOR UPDATE',
            [$companyId, $employeeId],
        )->getRowArray();
        if ($employee === null || ! (bool) $employee['activo']) {
            $this->database->transRollback();
            throw new DomainException('El empleado no existe o está dado de baja.');
        }

        $equipment = $this->database->query(
            'SELECT id FROM equipos WHERE empresa_id = ? AND id = ? AND deleted_at IS NULL FOR UPDATE',
            [$companyId, $equipmentId],
        )->getRowArray();
        if ($equipment === null) {
            $this->database->transRollback();
            throw new DomainException('El móvil no existe en la empresa.');
        }

        $existingForEmployee = $this->database->query(
            'SELECT id, equipo_id, rol FROM employee_equipment_assignments WHERE empresa_id = ? AND empleado_id = ? AND fecha_hasta IS NULL FOR UPDATE',
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
            'SELECT id, empleado_id FROM employee_equipment_assignments WHERE empresa_id = ? AND equipo_id = ? AND rol = ? AND fecha_hasta IS NULL FOR UPDATE',
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
    ): void {
        $this->database->table('employee_equipment_assignments')
            ->where('empresa_id', $companyId)
            ->where('empleado_id', $employeeId)
            ->where('fecha_hasta', null)
            ->update([
                'fecha_hasta' => $endsAt->format('Y-m-d'),
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);
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
