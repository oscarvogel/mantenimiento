<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripLocalityLookup;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripLeg;
use DomainException;

final class CreateTrip
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripLegRepository $legs,
        private readonly TripFleetLookup $fleet,
        private readonly TripDirectoryLookup $directory,
        private readonly TripLocalityLookup $localities,
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
            foreach ($command->trailerIds as $trailerId) {
                $trailer = $this->fleet->equipment($companyId, $trailerId);
                if ($trailer === null) {
                    throw new DomainException('El semirremolque elegido no existe en tu empresa.');
                }
                if ($trailer['tractor'] && ! $trailer['trailer']) {
                    throw new DomainException('El equipo elegido no es un semirremolque; revisá la asignación.');
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

            $this->assertLegs($companyId, $command);

            $conflicts = $this->trips->conflicts(
                $companyId,
                array_values(array_unique($equipmentIds)),
                $command->driverIds,
                $command->departureAt,
                $command->estimatedReturnAt,
            );
            if ($conflicts !== []) {
                throw new DomainException($conflicts[0]->describe() . ' Elegí otra unidad, otro chofer u otro horario.');
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

            $number = $this->trips->nextNumber($companyId);
            $trip = Trip::startImmediately(
                $companyId,
                $command->branchId,
                $number,
                $command->departureAt,
                $command->estimatedReturnAt,
                $assignments,
                [],
                $command->departureKm,
                $command->notes,
            );
            $id = $this->trips->add($trip, $actor->userId());
            foreach ($command->legs as $input) {
                $this->legs->add($companyId, $command->branchId, $id, new TripLeg(
                    $this->locality($input->originLocalityId)['name'],
                    $this->locality($input->destinationLocalityId)['name'],
                    $input->invoiceAmount,
                    $input->liquidationAmount,
                    $input->kilometers,
                    $input->customerId,
                    $input->currency,
                    $input->exchangeRate,
                    $input->notes,
                    $input->originDestinationCode,
                    $input->tonnes,
                    $input->originLocalityId,
                    $input->destinationLocalityId,
                ), $actor->userId());
            }

            return new CreateTripResult($id, $number);
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

    private function assertLegs(int $companyId, CreateTripCommand $command): void
    {
        foreach ($command->legs as $leg) {
            if (! $this->directory->customerExists($companyId, $leg->customerId)) {
                throw new DomainException('Uno de los clientes de los tramos no existe o está inactivo.');
            }
            if (! $this->directory->currencyExists($leg->currency)) {
                throw new DomainException('Una de las monedas de los tramos no está disponible.');
            }
            $this->locality($leg->originLocalityId);
            $this->locality($leg->destinationLocalityId);
        }
    }

    /** @return array{id:int,name:string,label:string} */
    private function locality(?int $localityId): array
    {
        $locality = $localityId === null ? null : $this->localities->find($localityId);
        if ($locality === null) {
            throw new DomainException('Una localidad de origen o destino ya no está disponible. Volvé a elegirla.');
        }
        return $locality;
    }
}
