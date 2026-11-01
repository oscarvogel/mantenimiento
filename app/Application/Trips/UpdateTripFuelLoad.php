<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripLegRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripFuelLoad;
use App\Domain\Trips\TripMoney;
use DomainException;

final class UpdateTripFuelLoad
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripDirectoryLookup $directory,
        private readonly TripLegRepository $legs,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, UpdateTripFuelLoadCommand $command): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if (! $this->directory->currencyExists($command->currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }
        if ($command->exchangeRate <= 0) {
            throw new DomainException('La cotización debe ser mayor que cero.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            $this->assertEditable($trip);
            if ($command->legId !== null && ! $this->legs->belongsToTrip($companyId, $trip->id() ?? 0, $command->legId)) {
                throw new DomainException('El tramo elegido no pertenece al viaje.');
            }

            $load = new TripFuelLoad(
                $command->date,
                $command->liters,
                new TripMoney($command->amount, $command->currency, $command->exchangeRate),
                $command->odometer,
                $command->station,
                $command->receipt,
                $command->notes,
            );
            if (! $this->costs->updateFuel($trip->id() ?? 0, $companyId, $command->movementId, $load, $actor->userId(), $command->legId)) {
                throw new DomainException('La carga de combustible no existe en este viaje o ya fue quitada.');
            }
        });
    }

    private function assertEditable(Trip $trip): void
    {
        if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
            throw new DomainException('No se puede editar combustible en un viaje cancelado o finalizado.');
        }
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para editar combustible de viajes.');
        }

        return $companyId;
    }
}
