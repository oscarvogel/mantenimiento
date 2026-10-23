<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripMoney;
use DomainException;

final class RegisterTripCashMovement
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripDirectoryLookup $directory,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, RegisterTripCashMovementCommand $command): int
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if (! $this->directory->currencyExists($command->currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds): int {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se puede mover la caja de un viaje cancelado o finalizado.');
            }

            $movement = new TripCashMovement(
                $command->type,
                $command->date,
                new TripMoney($command->amount, $command->currency, $command->exchangeRate),
                $command->notes,
            );

            return $this->costs->addCashMovement($trip->id() ?? 0, $companyId, $trip->branchId(), $movement, $actor->userId());
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para mover la caja de viajes.');
        }

        return $companyId;
    }
}
