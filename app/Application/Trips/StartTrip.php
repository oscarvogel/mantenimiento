<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripClock;
use App\Application\Trips\Port\TripsUnitOfWork;
use DateTimeImmutable;
use DomainException;

final class StartTrip
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripsUnitOfWork $unitOfWork,
        private readonly TripClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId, ?DateTimeImmutable $at, int $departureKm): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if ($tripId <= 0) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $companyId, $branchIds, $tripId, $at, $departureKm): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            $trip->start($at ?? $this->clock->now(), $departureKm);
            $this->trips->save($trip, $actor->userId());
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para iniciar viajes.');
        }

        return $companyId;
    }
}
