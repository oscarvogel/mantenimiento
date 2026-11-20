<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripLegCancellationStore
{
    /**
     * Estado del tramo para decidir si se puede anular, o null si no
     * pertenece al viaje.
     *
     * @return array{cancelled:bool,assigned_costs:int}|null
     */
    public function state(int $companyId, int $tripId, int $legId): ?array;

    /** Marca el tramo como anulado y deja la auditoría con el antes y el después. */
    public function cancel(int $companyId, int $branchId, int $tripId, int $legId, string $reason, int $actorUserId): void;
}
