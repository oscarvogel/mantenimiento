<?php

declare(strict_types=1);

namespace App\Application\Platform\Port;

use App\Application\Identity\ActorContext;

/**
 * Lectura del tablero de Mantenimiento que el portal resume. Ya viene filtrada
 * por empresa, sucursal y permisos del actor.
 */
interface PortalMaintenanceSummary
{
    /**
     * @return array{
     *   metrics: array<string,mixed>,
     *   upcomingMaintenance: list<array<string,mixed>>,
     *   readingAttention: list<array<string,mixed>>
     * }
     */
    public function fetch(ActorContext $actor): array;
}
