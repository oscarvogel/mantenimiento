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
use DomainException;

final class UpdateTripCashMovement
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripDirectoryLookup $directory,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, UpdateTripCashMovementCommand $command): void
    {
        $companyId = $this->company($actor);
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
                throw new DomainException('No se puede editar la caja de un viaje cancelado o finalizado.');
            }

            $current = $this->costs->findCashMovement($trip->id() ?? 0, $companyId, $command->movementId);
            if ($current === null) {
                throw new DomainException('El movimiento de caja no existe en este viaje o ya fue quitado.');
            }

            $resolved = TripMoneyRules::resolveForUpdate(
                $this->directory,
                $companyId,
                $command->amount,
                $command->currency,
                $command->exchangeRate,
                $command->confirmForeignRateOne,
                [
                    'importe' => (float) ($current['importe'] ?? 0),
                    'moneda' => (string) ($current['moneda'] ?? 'ARS'),
                    'cotizacion' => (float) ($current['cotizacion'] ?? 1),
                ],
            );

            $movement = new TripCashMovement(strtoupper($command->type), $command->date, $resolved['money'], $command->notes);
            if ($resolved['changed']) {
                $reason = trim((string) $command->changeReason);
                if ($reason === '') {
                    throw new DomainException('Indicá el motivo del cambio monetario.');
                }
            }

            if (! $this->costs->updateCashMovement($trip->id() ?? 0, $companyId, $command->movementId, $movement, $actor->userId(), $command->changeReason)) {
                throw new DomainException('El movimiento de caja no existe en este viaje o ya fue quitado.');
            }
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para editar la caja de viajes.');
        }

        return $companyId;
    }
}
