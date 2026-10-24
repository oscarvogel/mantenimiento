<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use DateTimeImmutable;

/**
 * Solicitud de apertura de una orden de trabajo de gomeria.
 *
 * La gomeria no es un modulo paralelo: se apoya en WorkOrders. Este comando es
 * el contrato que Neumaticos entrega al contexto de ordenes de trabajo.
 */
final readonly class LaunchGomeriaOrderCommand
{
    public function __construct(
        public int $companyId,
        public int $branchId,
        public int $equipmentId,
        public int $neumaticId,
        public string $reason,
        public int $kilometers,
        public DateTimeImmutable $at,
        public ?int $reportedByEmployeeId = null,
    ) {
    }
}
