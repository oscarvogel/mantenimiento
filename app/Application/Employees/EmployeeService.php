<?php

declare(strict_types=1);

namespace App\Application\Employees;

use App\Application\Employees\Port\EmployeeAssignmentRepository;
use App\Application\Employees\Port\EmployeeRepository;
use App\Application\Identity\ActorContext;
use App\Domain\Employees\Employee;
use DateTimeImmutable;
use DomainException;

final class EmployeeService
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly EmployeeAssignmentRepository $assignments,
    ) {}

    public function create(
        ActorContext $actor,
        string $firstName,
        string $lastName = '',
        ?string $document = null,
        ?string $cuil = null,
        ?string $employeeNumber = null,
        ?string $phone = null,
        ?string $email = null,
        ?DateTimeImmutable $hiredAt = null,
        ?string $notes = null,
        bool $importedIncomplete = false,
    ): int {
        $companyId = $this->tenant($actor, 'empleados.editar');

        $employee = Employee::create(
            $companyId,
            $firstName,
            $lastName,
            $document,
            $cuil,
            $employeeNumber,
            $phone,
            $email,
            $hiredAt,
            $notes,
            $importedIncomplete,
        );

        if ($employee->document() !== null && $this->employees->documentExists($companyId, $employee->document())) {
            throw new DomainException('Ya existe un empleado con ese documento en la empresa.');
        }
        if ($employee->employeeNumber() !== null && $this->employees->employeeNumberExists($companyId, $employee->employeeNumber())) {
            throw new DomainException('Ya existe un empleado con ese legajo en la empresa.');
        }

        return $this->employees->add($employee, $actor->userId());
    }

    public function assignDriver(
        ActorContext $actor,
        int $employeeId,
        int $equipmentId,
        DateTimeImmutable $startsAt,
        ?string $notes = null,
    ): int {
        $companyId = $this->tenant($actor, 'empleados.editar');

        return $this->assignments->assignDriver(
            $companyId,
            $employeeId,
            $equipmentId,
            $startsAt,
            $notes,
            $actor->userId(),
        );
    }

    public function terminate(
        ActorContext $actor,
        int $employeeId,
        DateTimeImmutable $terminatedAt,
        string $reason,
    ): void {
        $companyId = $this->tenant($actor, 'empleados.editar');
        $employee = $this->employees->findForUpdate($companyId, $employeeId);
        if ($employee === null) {
            throw new DomainException('El empleado no existe en la empresa.');
        }

        $employee->terminate($terminatedAt, $reason);
        $this->employees->save($employee, $actor->userId());
        $this->assignments->closeCurrentForEmployee($companyId, $employeeId, $terminatedAt, $actor->userId());
    }

    /** @return list<array<string,mixed>> */
    public function list(ActorContext $actor, ?bool $active = true, ?string $search = null): array
    {
        $companyId = $this->tenant($actor, 'empleados.ver');
        return $this->employees->list($companyId, $active, $search);
    }

    private function tenant(ActorContext $actor, string $permission): int
    {
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenés permiso para gestionar empleados.');
        }

        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || $companyId <= 0) {
            throw new DomainException('La gestión de empleados requiere una empresa activa.');
        }

        return $companyId;
    }
}
