<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use DomainException;

final class UpdateTrip
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripFleetLookup $fleet,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId, UpdateTripCommand $command): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if ($tripId <= 0) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $companyId, $branchIds, $tripId, $command): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('Solo se puede editar un viaje planificado o en curso.');
            }

            $truck = $this->fleet->equipment($companyId, $command->truckId);
            if ($truck === null) {
                throw new DomainException('El tractor o camión elegido no existe en tu empresa.');
            }
            if ($truck['trailer'] && ! $truck['tractor']) {
                throw new DomainException('El equipo elegido es un acoplado; elegí un tractor o camión.');
            }
            if ((int) $truck['branchId'] !== $trip->branchId()) {
                throw new DomainException('El tractor o camión elegido pertenece a otra sucursal del viaje.');
            }
            $this->assertEquipmentBranch($actor, $companyId, $truck);

            $equipmentIds = [$command->truckId];
            foreach ($command->trailerIds as $trailerId) {
                $trailer = $this->fleet->equipment($companyId, $trailerId);
                if ($trailer === null) {
                    throw new DomainException('El semirremolque elegido no existe en tu empresa.');
                }
                if ($trailer['tractor'] && ! $trailer['trailer']) {
                    throw new DomainException('El equipo elegido no es un semirremolque; revisá la asignación.');
                }
                if ((int) $trailer['branchId'] !== $trip->branchId()) {
                    throw new DomainException('El semirremolque elegido pertenece a otra sucursal del viaje.');
                }
                $this->assertEquipmentBranch($actor, $companyId, $trailer);
                $equipmentIds[] = $trailerId;
            }

            foreach ($command->driverIds as $driverId) {
                $driver = $this->fleet->driver($companyId, $driverId);
                if ($driver === null || ! $driver['active']) {
                    throw new DomainException('Uno de los choferes elegidos no existe o está inactivo.');
                }
            }

            $conflicts = $this->trips->conflicts(
                $companyId,
                array_values(array_unique($equipmentIds)),
                $command->driverIds,
                $command->departureAt,
                $command->estimatedReturnAt,
                $tripId,
            );
            if ($conflicts !== []) {
                throw new DomainException($conflicts[0]->describe() . ' Elegí otra unidad, otro chofer u otra fecha.');
            }

            $assignments = [
                new TripAssignment(TripAssignment::TRACTOR, $command->truckId, $command->departureAt, $command->estimatedReturnAt),
            ];
            foreach ($command->trailerIds as $trailerId) {
                $assignments[] = new TripAssignment(TripAssignment::ACOPLADO, $trailerId, $command->departureAt, $command->estimatedReturnAt);
            }
            foreach ($command->driverIds as $driverId) {
                $assignments[] = new TripAssignment(
                    TripAssignment::CHOFER,
                    $driverId,
                    $command->departureAt,
                    $command->estimatedReturnAt,
                    null,
                    $command->driverPercentages[$driverId] ?? null,
                );
            }

            $trip->updateOpenDetails(
                $command->departureAt,
                $command->estimatedReturnAt,
                $command->departureKm,
                $assignments,
                $command->notes,
            );
            $this->trips->updateDetails($trip, $actor->userId());
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para editar viajes.');
        }

        return $companyId;
    }

    /** @param array{branchId:int} $equipment */
    private function assertEquipmentBranch(ActorContext $actor, int $companyId, array $equipment): void
    {
        if (! $actor->canAccessBranch($companyId, $equipment['branchId'])) {
            throw new DomainException('El equipo elegido pertenece a una sucursal que no tenés autorizada.');
        }
    }
}
