<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\DashboardOverview;
use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\GetCircuitOverview;

final class MaintenanceCircuitDashboardOverview implements DashboardOverview
{
    public function __construct(private readonly GetCircuitOverview $overview)
    {
    }

    public function fetch(ActorContext $actor): array
    {
        return $this->overview->execute($actor);
    }
}
