<?php

declare(strict_types=1);

namespace App\Application\Employees\Port;

/**
 * Frontera transaccional para las operaciones de empleados y asignaciones.
 *
 * El caso de uso es el dueño de la transacción: cierre de asignación, baja de
 * notificaciones y auditoría se confirman o revierten juntos.
 */
interface EmployeeUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
