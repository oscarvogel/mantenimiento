<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Application\Dashboard\GetMaintenanceDashboard;
use App\Application\Identity\ActorContext;
use App\Application\Platform\Port\PortalMaintenanceSummary;

/** Reusa el tablero de Mantenimiento: el portal no calcula indicadores propios. */
final readonly class MaintenanceDashboardPortalSummary implements PortalMaintenanceSummary
{
    public function __construct(private GetMaintenanceDashboard $dashboard)
    {
    }

    public function fetch(ActorContext $actor): array
    {
        $data = $this->dashboard->execute($actor);

        return [
            'metrics' => $data['metrics'],
            'upcomingMaintenance' => $data['upcomingMaintenance'],
            'readingAttention' => $data['readingAttention'],
        ];
    }
}
