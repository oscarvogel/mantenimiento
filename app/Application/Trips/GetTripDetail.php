<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\TripDetailReadModel;
use DomainException;

final class GetTripDetail
{
    public function __construct(private readonly TripDetailReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $tripId): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenes permiso para consultar el detalle de viajes.');
        }
        if ($tripId <= 0) {
            throw new DomainException('El viaje solicitado no existe o no esta autorizado.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no esta autorizado.');
        }

        $detail = $this->readModel->find($actor->companyId(), $branchIds, $tripId);
        if ($detail === null) {
            throw new DomainException('El viaje solicitado no existe o no esta autorizado.');
        }

        return $detail;
    }
}
