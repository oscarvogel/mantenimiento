<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use DomainException;

/**
 * Pasa a «Caja del chofer» los gastos del viaje que estaban marcados como
 * pagados por la empresa.
 *
 * Es una corrección explícita del operador: el sistema no puede deducir si un
 * gasto lo pagó la empresa o salió del adelanto. Al imputarlo a la caja, el
 * gasto deja de ser un costo pagado aparte y descuenta el saldo del adelanto.
 */
final class ReattributeTripExpensesToCash
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para corregir gastos de viajes.');
        }
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $companyId, $branchIds, $tripId): int {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('Solo se pueden corregir gastos en un viaje abierto.');
            }

            return $this->costs->reattributeCompanyExpensesToCash(
                $trip->id() ?? 0,
                $companyId,
                $actor->userId(),
            );
        });
    }
}
