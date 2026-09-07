<?php

declare(strict_types=1);

namespace App\Application\Employees;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Employees\CodeIgniterEmployeeAssignmentRepository;
use App\Infrastructure\Employees\CodeIgniterEmployeeRepository;
use DateTimeImmutable;
use DomainException;

final class ConfirmDriverAssignmentsImport
{
    public function __construct(
        private readonly CodeIgniterEmployeeRepository $employees,
        private readonly CodeIgniterEmployeeAssignmentRepository $assignments,
    ) {}

    /**
     * @param list<array{
     *   status:string,equipmentId:int|null,employeeId:int|null,action:string,
     *   driverName:string|null,plate:string,sheet:string,rowNumber:int
     * }> $rows
     * @return array{createdEmployees:int,assignedDrivers:int,unchanged:int,withoutDriver:int}
     */
    public function execute(ActorContext $actor, array $rows): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La importación de choferes requiere una empresa activa.');
        }
        if (! $actor->hasPermission('importaciones.cargar') || ! $actor->hasPermission('empleados.editar')) {
            throw new DomainException('No tenés permiso para confirmar choferes.');
        }

        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== DriverAssignmentImportPreviewBuilder::STATUS_OK) {
                throw new DomainException('La importación tiene filas con errores o advertencias. Resolvelas antes de confirmar.');
            }
        }

        $companyId = $actor->companyId();
        $today = new DateTimeImmutable('today');
        $result = ['createdEmployees' => 0, 'assignedDrivers' => 0, 'unchanged' => 0, 'withoutDriver' => 0];

        foreach ($rows as $row) {
            $action = (string) ($row['action'] ?? '');
            if ($action === 'SIN_CHOFER') {
                $result['withoutDriver']++;
                continue;
            }

            $equipmentId = (int) ($row['equipmentId'] ?? 0);
            if ($equipmentId <= 0) {
                throw new DomainException('Una fila confirmable quedó sin móvil asociado.');
            }

            $employeeId = (int) ($row['employeeId'] ?? 0);
            if ($action === 'CREAR_Y_ASIGNAR') {
                $driverName = trim((string) ($row['driverName'] ?? ''));
                if ($driverName === '') {
                    throw new DomainException('No se puede crear un empleado sin nombre.');
                }

                $employeeId = $this->employees->add(
                    \App\Domain\Employees\Employee::create(
                        $companyId,
                        $driverName,
                        importedIncomplete: true,
                    ),
                    $actor->userId(),
                );
                $result['createdEmployees']++;
            }

            if ($employeeId <= 0) {
                throw new DomainException('Una fila confirmable quedó sin empleado asociado.');
            }

            $current = $this->assignments->currentDriverForEquipment($companyId, $equipmentId);
            if ($current !== null && $current->employeeId() === $employeeId) {
                $result['unchanged']++;
                continue;
            }

            $this->assignments->assignDriver(
                $companyId,
                $employeeId,
                $equipmentId,
                $today,
                'Importado desde planilla de choferes',
                $actor->userId(),
            );
            $result['assignedDrivers']++;
        }

        return $result;
    }
}
