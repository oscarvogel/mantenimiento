<?php

declare(strict_types=1);

namespace App\Application\Employees\Port;

/**
 * Cancela las entregas de WhatsApp que todavía dependen de una asignación
 * vigente y que dejarían de ser válidas al desvincular al chofer.
 *
 * Solo se cancelan entregas aún no enviadas (`PENDIENTE`/`REINTENTO`). Las que
 * ya fueron aceptadas por el gateway no se pueden retirar.
 */
interface PendingDriverNotifications
{
    public function cancelForAssignment(
        int $companyId,
        int $equipmentId,
        int $employeeId,
        int $assignmentId,
        string $reason,
    ): int;
}
