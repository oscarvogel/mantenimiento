<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use DomainException;

final class CreateTrip
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripFleetLookup $fleet,
        private readonly TripDirectoryLookup $directory,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, CreateTripCommand $command): CreateTripResult
    {
        $companyId = $this->company($actor);

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): CreateTripResult {
            $this->assertBranch($actor, $companyId, $command->branchId);

            $truck = $this->fleet->equipment($companyId, $command->truckId);
            if ($truck === null) {
                throw new DomainException('El tractor o camión elegido no existe en tu empresa.');
            }
            if ($truck['trailer'] && ! $truck['tractor']) {
                throw new DomainException('El equipo elegido es un acoplado; elegí un tractor o camión.');
            }
            $this->assertEquipmentBranch($actor, $companyId, $truck);

            $equipmentIds = [$command->truckId];
            if ($command->trailerId !== null) {
                $trailer = $this->fleet->equipment($companyId, $command->trailerId);
                if ($trailer === null) {
                    throw new DomainException('El acoplado elegido no existe en tu empresa.');
                }
                if ($trailer['tractor'] && ! $trailer['trailer']) {
                    throw new DomainException('El equipo elegido no es un acoplado; revisá la asignación.');
                }
                $this->assertEquipmentBranch($actor, $companyId, $trailer);
                $equipmentIds[] = $command->trailerId;
            }

            foreach ($command->driverIds as $driverId) {
                $driver = $this->fleet->driver($companyId, $driverId);
                if ($driver === null || ! $driver['active']) {
                    throw new DomainException('Uno de los choferes elegidos no existe o está inactivo.');
                }
            }

            $this->assertStops($companyId, $command);

            $conflicts = $this->trips->conflicts(
                $companyId,
                array_values(array_unique($equipmentIds)),
                $command->driverIds,
                $command->plannedDeparture,
                $command->plannedReturn,
            );
            if ($conflicts !== []) {
                throw new DomainException($conflicts[0]->describe() . ' Elegí otra unidad, otro chofer u otro horario.');
            }

            $assignments = [
                new TripAssignment(TripAssignment::TRACTOR, $command->truckId, $command->plannedDeparture, $command->plannedReturn),
            ];
            if ($command->trailerId !== null) {
                $assignments[] = new TripAssignment(TripAssignment::ACOPLADO, $command->trailerId, $command->plannedDeparture, $command->plannedReturn);
            }
            foreach ($command->driverIds as $driverId) {
                $assignments[] = new TripAssignment(
                    TripAssignment::CHOFER,
                    $driverId,
                    $command->plannedDeparture,
                    $command->plannedReturn,
                    null,
                    $command->driverPercentages[$driverId] ?? null,
                );
            }

            $stops = [];
            foreach ($command->stops as $index => $stop) {
                $stops[] = $stop->toDomain($index + 1);
            }

            $number = $this->trips->nextNumber($companyId);
            $trip = Trip::plan(
                $companyId,
                $command->branchId,
                $number,
                $command->plannedDeparture,
                $command->plannedReturn,
                $assignments,
                $stops,
                $command->notes,
                $command->departureKm,
            );
            $id = $this->trips->add($trip, $actor->userId());

            return new CreateTripResult($id, $number);
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para planificar viajes.');
        }

        return $companyId;
    }

    private function assertBranch(ActorContext $actor, int $companyId, int $branchId): void
    {
        if (! $actor->canAccessBranch($companyId, $branchId)) {
            throw new DomainException('La sucursal de origen elegida no está autorizada para tu usuario.');
        }
    }

    /** @param array{branchId:int} $equipment */
    private function assertEquipmentBranch(ActorContext $actor, int $companyId, array $equipment): void
    {
        if (! $actor->canAccessBranch($companyId, $equipment['branchId'])) {
            throw new DomainException('El equipo elegido pertenece a una sucursal que no tenés autorizada.');
        }
    }

    private function assertStops(int $companyId, CreateTripCommand $command): void
    {
        foreach ($command->stops as $stop) {
            if ($stop->customerId !== null && ! $this->directory->customerExists($companyId, $stop->customerId)) {
                throw new DomainException('Uno de los clientes elegidos no existe o está inactivo.');
            }
            if ($stop->loadUnitId !== null && ! $this->directory->loadUnitExists($companyId, $stop->loadUnitId)) {
                throw new DomainException('Una de las unidades de carga elegidas no existe o está inactiva.');
            }
            if ($stop->addressId === null) {
                continue;
            }
            if ($stop->customerId === null) {
                throw new DomainException('Para usar un domicilio guardado, elegí también el cliente de la parada.');
            }
            if (! $this->directory->addressBelongsToCustomer($companyId, $stop->customerId, $stop->addressId)) {
                throw new DomainException('El domicilio elegido no pertenece al cliente de la parada.');
            }
        }
    }
}
