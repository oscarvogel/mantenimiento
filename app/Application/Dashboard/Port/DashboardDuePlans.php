<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Port;

use App\Application\Identity\ActorContext;

interface DashboardDuePlans
{
    /** @return list<array{plan: \App\Domain\PreventiveMaintenance\PlanMantenimiento, evaluation: \App\Domain\PreventiveMaintenance\EvaluacionPlan}> */
    public function fetch(ActorContext $actor, int $companyId): array;
}
