<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripMoney;
use App\Domain\Trips\TripOtherEarning;
use DomainException;

final class RegisterTripOtherEarning
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripFleetLookup $fleet,
        private readonly TripDirectoryLookup $directory,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, RegisterTripOtherEarningCommand $command): int
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if (! $this->directory->currencyExists($command->currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }
        $driver = $this->fleet->driver($companyId, $command->employeeId);
        if ($driver === null || ! $driver['active']) {
            throw new DomainException('El chofer elegido no existe o está inactivo.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds): int {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se pueden cargar haberes en un viaje cerrado o cancelado.');
            }
            if (! in_array($command->employeeId, $trip->driverIds(), true)) {
                throw new DomainException('El chofer elegido no está asignado al viaje.');
            }

            $earning = new TripOtherEarning(
                $command->employeeId,
                $command->date,
                new TripMoney($command->amount, $command->currency, $command->exchangeRate),
                $command->concept,
                $command->notes,
            );

            return $this->costs->addOtherEarning($trip->id() ?? 0, $companyId, $trip->branchId(), $earning, $actor->userId());
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para cargar haberes de viajes.');
        }

        return $companyId;
    }
}
