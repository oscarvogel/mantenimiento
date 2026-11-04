<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use DomainException;

final class UpdateTripDriverPercentage
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, UpdateTripDriverPercentageCommand $command): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if ($command->percentage < 0 || $command->percentage > 100) {
            throw new DomainException('El porcentaje de liquidación debe estar entre 0 y 100.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se puede modificar el porcentaje de un viaje cerrado o cancelado.');
            }
            if (! in_array($command->employeeId, $trip->driverIds(), true)) {
                throw new DomainException('El chofer elegido no está asignado al viaje.');
            }

            $this->trips->updateDriverPercentage($companyId, $trip->id() ?? 0, $command->employeeId, $command->percentage, $actor->userId());
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para modificar el porcentaje del viaje.');
        }

        return $companyId;
    }
}
