<?php

declare(strict_types=1);

namespace App\Application\Employees\Port;

/**
 * Resuelve una persona para el circuito operativo.
 *
 * Lo consumen los contextos que asignan un chofer: Viajes al crear el viaje y
 * al imputar otros haberes. Responde Employees, que es el dueno de `empleados`.
 *
 * Devuelve datos planos con el scope de empresa ya resuelto.
 */
interface EmployeeDirectoryLookup
{
    /**
     * @return array{id:int,label:string,active:bool}|null
     *         Null cuando la persona no existe en la empresa o fue dada de baja.
     */
    public function driver(int $companyId, int $employeeId): ?array;
}
