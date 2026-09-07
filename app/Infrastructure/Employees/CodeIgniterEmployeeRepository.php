<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

use App\Application\Employees\Port\EmployeeRepository;
use App\Domain\Employees\Employee;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterEmployeeRepository implements EmployeeRepository
{
    public function __construct(private readonly BaseConnection $database) {}

    public function documentExists(int $companyId, string $document, ?int $excludingId = null): bool
    {
        $builder = $this->database->table('empleados')
            ->where('empresa_id', $companyId)
            ->where('documento', $document)
            ->where('deleted_at', null);
        if ($excludingId !== null) {
            $builder->where('id !=', $excludingId);
        }
        return $builder->countAllResults() > 0;
    }

    public function employeeNumberExists(int $companyId, string $employeeNumber, ?int $excludingId = null): bool
    {
        $builder = $this->database->table('empleados')
            ->where('empresa_id', $companyId)
            ->where('legajo', $employeeNumber)
            ->where('deleted_at', null);
        if ($excludingId !== null) {
            $builder->where('id !=', $excludingId);
        }
        return $builder->countAllResults() > 0;
    }

    public function add(Employee $employee, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('empleados')->insert([
            'empresa_id' => $employee->companyId(),
            'nombre' => $employee->firstName(),
            'apellido' => $employee->lastName(),
            'documento' => $employee->document(),
            'cuil' => $employee->cuil(),
            'legajo' => $employee->employeeNumber(),
            'telefono' => $employee->phone(),
            'email' => $employee->email(),
            'fecha_ingreso' => $employee->hiredAt()?->format('Y-m-d'),
            'observaciones' => $employee->notes(),
            'activo' => $employee->isActive() ? 1 : 0,
            'fecha_baja' => $employee->terminatedAt()?->format('Y-m-d'),
            'motivo_baja' => $employee->terminationReason(),
            'importado_incompleto' => $employee->isImportedIncomplete() ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo crear el empleado.');
        }
        return $id;
    }

    public function findForUpdate(int $companyId, int $employeeId): ?Employee
    {
        $row = $this->database->query(
            'SELECT * FROM empleados WHERE empresa_id = ? AND id = ? AND deleted_at IS NULL FOR UPDATE',
            [$companyId, $employeeId],
        )->getRowArray();

        return $row === null ? null : $this->hydrate($row);
    }

    public function save(Employee $employee, int $actorUserId): void
    {
        $id = $employee->id();
        if ($id === null) {
            throw new RuntimeException('No se puede actualizar un empleado sin identidad.');
        }

        $this->database->table('empleados')
            ->where('empresa_id', $employee->companyId())
            ->where('id', $id)
            ->where('deleted_at', null)
            ->update([
                'nombre' => $employee->firstName(),
                'apellido' => $employee->lastName(),
                'documento' => $employee->document(),
                'cuil' => $employee->cuil(),
                'legajo' => $employee->employeeNumber(),
                'telefono' => $employee->phone(),
                'email' => $employee->email(),
                'fecha_ingreso' => $employee->hiredAt()?->format('Y-m-d'),
                'observaciones' => $employee->notes(),
                'activo' => $employee->isActive() ? 1 : 0,
                'fecha_baja' => $employee->terminatedAt()?->format('Y-m-d'),
                'motivo_baja' => $employee->terminationReason(),
                'importado_incompleto' => $employee->isImportedIncomplete() ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);
    }

    public function list(int $companyId, ?bool $active = true, ?string $search = null): array
    {
        $builder = $this->database->table('empleados e')
            ->select('e.*')
            ->where('e.empresa_id', $companyId)
            ->where('e.deleted_at', null)
            ->orderBy('e.apellido', 'ASC')
            ->orderBy('e.nombre', 'ASC');

        if ($active !== null) {
            $builder->where('e.activo', $active ? 1 : 0);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $builder->groupStart()
                ->like('e.nombre', $search)
                ->orLike('e.apellido', $search)
                ->orLike('e.documento', $search)
                ->orLike('e.legajo', $search)
                ->groupEnd();
        }

        return $builder->get()->getResultArray();
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Employee
    {
        return Employee::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (string) $row['nombre'],
            (string) ($row['apellido'] ?? ''),
            $row['documento'] === null ? null : (string) $row['documento'],
            $row['cuil'] === null ? null : (string) $row['cuil'],
            $row['legajo'] === null ? null : (string) $row['legajo'],
            $row['telefono'] === null ? null : (string) $row['telefono'],
            $row['email'] === null ? null : (string) $row['email'],
            empty($row['fecha_ingreso']) ? null : new DateTimeImmutable((string) $row['fecha_ingreso']),
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            (bool) $row['activo'],
            empty($row['fecha_baja']) ? null : new DateTimeImmutable((string) $row['fecha_baja']),
            $row['motivo_baja'] === null ? null : (string) $row['motivo_baja'],
            (bool) $row['importado_incompleto'],
        );
    }
}
