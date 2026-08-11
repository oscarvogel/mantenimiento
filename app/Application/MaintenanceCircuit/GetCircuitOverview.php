<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\CircuitOverviewPort;
use DomainException;

final class GetCircuitOverview
{
    public function __construct(private readonly CircuitOverviewPort $overview)
    {
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor, ?CircuitOverviewPagination $pagination = null): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El circuito operativo requiere una cuenta perteneciente a una empresa.');
        }

        if (! $this->canSeeCircuit($actor)) {
            throw new DomainException('No tenés permiso para consultar el circuito de mantenimiento.');
        }

        return $this->overview->fetch(
            $actor->companyId(),
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $pagination ?? new CircuitOverviewPagination(),
        );
    }

    private function canSeeCircuit(ActorContext $actor): bool
    {
        foreach (['equipos.ver', 'planes.ver', 'ordenes.ver', 'ordenes.mi_trabajo'] as $permission) {
            if ($actor->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
