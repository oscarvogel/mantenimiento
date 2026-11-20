<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripLegCancellationStore;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\TripLegCancellation;
use DomainException;

final class CancelTripLeg
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripLegRepository $legs,
        private readonly TripLegCancellationStore $cancellations,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId, int $legId, string $reason): void
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para anular tramos de viajes.');
        }
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $companyId, $branchIds, $tripId, $legId, $reason): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            $state = $this->cancellations->state($trip->companyId(), $tripId, $legId);
            if ($state === null) {
                throw new DomainException('El tramo solicitado no pertenece a este viaje.');
            }

            $normalized = TripLegCancellation::reason($trip->status(), $state['cancelled'], $state['assigned_costs'], $reason);
            $this->cancellations->cancel($trip->companyId(), $trip->branchId(), $tripId, $legId, $normalized, $actor->userId());

            // El regreso estimado sale del último tramo vigente.
            $trip->syncPlannedReturn($this->legs->lastLegEndDate($trip->companyId(), $tripId));
            $this->trips->updatePlannedReturn($trip->companyId(), $tripId, $trip->plannedReturn(), $actor->userId());
        });
    }
}
