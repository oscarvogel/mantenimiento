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
use App\Domain\Trips\TripExpense;
use DomainException;

final class UpdateTripExpense
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripDirectoryLookup $directory,
        private readonly TripLegRepository $legs,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, UpdateTripExpenseCommand $command): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if ($command->categoryId !== null && ! $this->directory->expenseCategoryExists($companyId, $command->categoryId)) {
            throw new DomainException('La categoría de gasto elegida no está disponible.');
        }
        if ($command->paymentMethodId !== null && ! $this->directory->paymentMethodExists($companyId, $command->paymentMethodId)) {
            throw new DomainException('El medio de pago elegido no está disponible.');
        }

        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se pueden editar gastos en un viaje cancelado o finalizado.');
            }
            if ($command->legId !== null && ! $this->legs->belongsToTrip($companyId, $trip->id() ?? 0, $command->legId)) {
                throw new DomainException('El tramo elegido no pertenece al viaje.');
            }

            $current = $this->costs->findExpense($trip->id() ?? 0, $companyId, $command->movementId);
            if ($current === null) {
                throw new DomainException('El gasto no existe en este viaje o ya fue quitado.');
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

            $expense = new TripExpense(
                $command->date,
                $resolved['money'],
                $command->categoryId,
                $command->paymentMethodId,
                $command->paidBy === 'CAJA',
                $command->receipt,
                $command->notes,
                $command->paidBy,
                $current['concepto_id'] === null ? null : (int) $current['concepto_id'],
                (float) ($current['cantidad'] ?? 1.0),
                $current['importe_unitario'] === null ? null : (float) $current['importe_unitario'],
            );
            if ($resolved['changed']) {
                $reason = trim((string) $command->changeReason);
                if ($reason === '') {
                    throw new DomainException('Indicá el motivo del cambio monetario.');
                }
            }

            if (! $this->costs->updateExpense($trip->id() ?? 0, $companyId, $command->movementId, $expense, $actor->userId(), $command->legId, $command->changeReason)) {
                throw new DomainException('El gasto no existe en este viaje o ya fue quitado.');
            }
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para editar gastos de viajes.');
        }

        return $companyId;
    }
}
