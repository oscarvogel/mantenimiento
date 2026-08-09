<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\DashboardDuePlans;
use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ConsultarVencimientos;

final class PreventiveDashboardDuePlans implements DashboardDuePlans
{
    public function __construct(private readonly ConsultarVencimientos $duePlans)
    {
    }

    public function fetch(ActorContext $actor, int $companyId): array
    {
        return $this->duePlans->execute($actor, $companyId);
    }
}
