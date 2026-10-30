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

final class AddTripLeg
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripLegRepository $legs,
        private readonly TripDirectoryLookup $directory,
        private readonly TripLocalityLookup $localities,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, AddTripLegCommand $command): int
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if (! $this->directory->currencyExists($command->currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }
        if ($command->customerId === null) {
            throw new DomainException('Elegí un cliente para el tramo.');
        }
        if (! $this->directory->customerExists($companyId, $command->customerId)) {
            throw new DomainException('El cliente elegido no existe o está inactivo.');
        }
        $origin = $this->locality($command->originLocalityId);
        $destination = $this->locality($command->destinationLocalityId);

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds, $origin, $destination): int {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se pueden agregar tramos a un viaje cerrado o cancelado.');
            }

            $leg = new TripLeg(
                $origin['name'],
                $destination['name'],
                $command->invoiceAmount,
                $command->liquidationAmount,
                $command->kilometers,
                $command->customerId,
                $command->currency,
                $command->exchangeRate,
                $command->notes,
                $command->originDestinationCode,
                $command->tonnes,
                $command->originLocalityId,
                $command->destinationLocalityId,
            );

            return $this->legs->add($trip->companyId(), $trip->branchId(), $command->tripId, $leg, $actor->userId());
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para cargar tramos de viajes.');
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
