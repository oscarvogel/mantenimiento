<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use DomainException;

final class CancelTrip
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId, string $reason): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $companyId, $branchIds, $tripId, $reason): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            $trip->cancel($reason);
            $this->trips->save($trip, $actor->userId());
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para cancelar viajes.');
        }

        return $companyId;
    }
}
