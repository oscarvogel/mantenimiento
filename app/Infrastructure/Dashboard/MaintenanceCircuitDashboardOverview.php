<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\DashboardOverview;
use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\CircuitOverviewPagination;
use App\Application\MaintenanceCircuit\GetCircuitOverview;

final class MaintenanceCircuitDashboardOverview implements DashboardOverview
{
    public function __construct(private readonly GetCircuitOverview $overview)
    {
    }

    public function fetch(ActorContext $actor): array
    {
        // El dashboard necesita una muestra más amplia que las grillas operativas
        // para construir indicadores gerenciales y detectar lecturas desactualizadas.
        $pageSizes = array_fill_keys(CircuitOverviewPagination::LISTS, 25);

        return $this->overview->execute($actor, new CircuitOverviewPagination([], $pageSizes));
    }
}
