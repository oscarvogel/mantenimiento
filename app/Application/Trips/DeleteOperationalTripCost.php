<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use DomainException;

final class DeleteOperationalTripCost
{
    public const FUEL = 'COMBUSTIBLE';
    public const EXPENSE = 'GASTO';
    public const CASH = 'CAJA';
    public const EARNING = 'HABER';

    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, DeleteOperationalTripCostCommand $command): void
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para quitar movimientos de viajes.');
        }
        if (! in_array($command->type, [self::FUEL, self::EXPENSE, self::CASH, self::EARNING], true)) {
            throw new DomainException('El tipo de movimiento no es válido.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se pueden quitar movimientos de un viaje cancelado o finalizado.');
            }
            if ($command->type === self::EARNING
                && $this->costs->logbookEntryIdForEarning($trip->id() ?? 0, $companyId, $command->movementId) !== null) {
                throw new DomainException('Este haber se genera desde el diario de bordo. Quitá la etapa desde el diario.');
            }

            $deleted = match ($command->type) {
                self::FUEL => $this->costs->softDeleteFuel($trip->id() ?? 0, $companyId, $command->movementId, $actor->userId()),
                self::EXPENSE => $this->costs->softDeleteExpense($trip->id() ?? 0, $companyId, $command->movementId, $actor->userId()),
                self::CASH => $this->costs->softDeleteCashMovement($trip->id() ?? 0, $companyId, $command->movementId, $actor->userId()),
                self::EARNING => $this->costs->softDeleteOtherEarning($trip->id() ?? 0, $companyId, $command->movementId, $actor->userId()),
            };
            if (! $deleted) {
                throw new DomainException('El movimiento no existe en este viaje o ya fue quitado.');
            }
        });
    }
}
