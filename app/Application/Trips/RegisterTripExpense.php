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
use App\Domain\Trips\TripMoney;
use DomainException;

final class RegisterTripExpense
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripDirectoryLookup $directory,
        private readonly TripLegRepository $legs,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, RegisterTripExpenseCommand $command): int
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        if (! $this->directory->currencyExists($command->currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }
        if ($command->categoryId !== null && ! $this->directory->expenseCategoryExists($companyId, $command->categoryId)) {
            throw new DomainException('La categoría de gasto elegida no está disponible.');
        }
        if ($command->paymentMethodId !== null && ! $this->directory->paymentMethodExists($companyId, $command->paymentMethodId)) {
            throw new DomainException('El medio de pago elegido no está disponible.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds): int {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se pueden cargar gastos en un viaje cancelado o finalizado.');
            }
            if ($command->legId !== null && ! $this->legs->belongsToTrip($companyId, $trip->id() ?? 0, $command->legId)) {
                throw new DomainException('El tramo elegido no pertenece al viaje.');
            }

            $expense = new TripExpense(
                $command->date,
                new TripMoney($command->amount, $command->currency, $command->exchangeRate),
                $command->categoryId,
                $command->paymentMethodId,
                $command->paidFromCash,
                $command->receipt,
                $command->notes,
                $command->paidBy,
            );

            return $this->costs->addExpense($trip->id() ?? 0, $companyId, $trip->branchId(), $expense, $actor->userId(), $command->legId);
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para cargar gastos de viajes.');
        }

        return $companyId;
    }
}
