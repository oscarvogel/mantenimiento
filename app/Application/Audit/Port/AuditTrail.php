<?php

declare(strict_types=1);

namespace App\Application\Audit\Port;

use App\Application\Audit\AuditEntry;

interface AuditTrail
{
    /**
     * Persiste una entrada de auditoría en la transacción vigente.
     *
     * Si no puede persistirse debe lanzar excepción para que la operación
     * sensible haga rollback.
     */
    public function record(AuditEntry $entry): void;
}
