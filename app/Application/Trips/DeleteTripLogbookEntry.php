<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripLogbookRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use DomainException;

final class DeleteTripLogbookEntry
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripLogbookRepository $logbook,
        private readonly TripCostRepository $costs,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId, int $entryId): void
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para quitar etapas del diario de bordo.');
        }
        if ($tripId <= 0 || $entryId <= 0) {
            throw new DomainException('La etapa del diario de bordo no es válida.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $tripId, $entryId, $companyId, $branchIds): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se puede editar el diario de bordo de un viaje cerrado o cancelado.');
            }

            $internalTripId = $trip->id() ?? 0;
            $linkedEarningId = $this->logbook->linkedEarningId($internalTripId, $companyId, $entryId);
            if ($linkedEarningId !== null) {
                $this->costs->softDeleteOtherEarning($internalTripId, $companyId, $linkedEarningId, $actor->userId());
            }

            if (! $this->logbook->softDelete($internalTripId, $companyId, $entryId, $actor->userId())) {
                throw new DomainException('La etapa del diario de bordo no existe en este viaje o ya fue quitada.');
            }
        });
    }
}
