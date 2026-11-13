<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripLocalityLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripLeg;
use DomainException;

final class UpdateTripLeg
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripLegRepository $legs,
        private readonly TripDirectoryLookup $directory,
        private readonly TripLocalityLookup $localities,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, UpdateTripLegCommand $command): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        $legCommand = $command->leg;
        if (! $this->directory->currencyExists($legCommand->currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }
        $liquidation = $legCommand->liquidation();
        if (! $this->directory->currencyExists($liquidation->currency())) {
            throw new DomainException('La moneda de liquidación elegida no está disponible.');
        }
        if ($legCommand->customerId === null) {
            throw new DomainException('Elegí un cliente para el tramo.');
        }
        if (! $this->directory->customerExists($companyId, $legCommand->customerId)) {
            throw new DomainException('El cliente elegido no existe o está inactivo.');
        }
        $origin = $this->locality($legCommand->originLocalityId);
        $destination = $this->locality($legCommand->destinationLocalityId);

        $this->unitOfWork->transactional(function () use ($actor, $command, $legCommand, $companyId, $branchIds, $origin, $destination, $liquidation): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $legCommand->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se pueden editar tramos de un viaje cerrado o cancelado.');
            }
            if (! $this->legs->belongsToTrip($trip->companyId(), $legCommand->tripId, $command->legId)) {
                throw new DomainException('El tramo solicitado no pertenece a este viaje.');
            }

            $leg = new TripLeg(
                $origin['name'],
                $destination['name'],
                $legCommand->invoice(),
                $liquidation,
                $legCommand->kilometers,
                $legCommand->customerId,
                $legCommand->notes,
                $legCommand->originDestinationCode,
                $legCommand->tonnes,
                $legCommand->originLocalityId,
                $legCommand->destinationLocalityId,
                $legCommand->startDate,
                $legCommand->endDate,
            );

            $this->legs->update($trip->companyId(), $trip->branchId(), $legCommand->tripId, $command->legId, $leg, $actor->userId());
            $this->syncPlannedReturn($trip, $actor->userId());
        });
    }

    /**
     * El regreso estimado del viaje se completa con la fecha de fin del
     * último tramo: el operador ya no lo carga a mano.
     */
    private function syncPlannedReturn(Trip $trip, int $actorUserId): void
    {
        $tripId = $trip->id();
        if ($tripId === null) {
            return;
        }
        $trip->syncPlannedReturn($this->legs->lastLegEndDate($trip->companyId(), $tripId));
        $this->trips->updatePlannedReturn($trip->companyId(), $tripId, $trip->plannedReturn(), $actorUserId);
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para editar tramos de viajes.');
        }

        return $companyId;
    }

    /** @return array{id:int,name:string,label:string} */
    private function locality(?int $localityId): array
    {
        $locality = $localityId === null ? null : $this->localities->find($localityId);
        if ($locality === null) {
            throw new DomainException('Elegí una localidad de origen y otra de destino para el tramo.');
        }
        return $locality;
    }
}
