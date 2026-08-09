<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\Port;

use App\Domain\WorkOrders\WorkOrderNumber;

interface WorkOrderNumberGenerator
{
    /** Debe ejecutarse dentro de la misma transacciÃ³n que crea la OT. */
    public function next(int $companyId, int $year): WorkOrderNumber;
}
