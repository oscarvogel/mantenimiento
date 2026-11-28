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
use App\Domain\Trips\TripOtherEarning;
use DomainException;

final class UpdateTripOtherEarning
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripCostRepository $costs,
        private readonly TripFleetLookup $fleet,
        private readonly TripDirectoryLookup $directory,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, UpdateTripOtherEarningCommand $command): void
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }
        // currency/rate validated later with TripMoneyRules to preserve historical rate when not changing monetary fields

        $driver = $this->fleet->driver($companyId, $command->employeeId);
        if ($driver === null || ! $driver['active']) {
            throw new DomainException('El chofer elegido no existe o está inactivo.');
        }

        $concept = $command->conceptId === null ? null : $this->directory->concept($companyId, $command->conceptId);
        if ($command->conceptId !== null && $concept === null) {
            throw new DomainException('El concepto elegido no está disponible.');
        }
        if ($concept !== null && ! in_array($concept['categoria'], ['HABER', 'AMBOS'], true)) {
            throw new DomainException('El concepto elegido no aplica a haberes.');
        }
        if ($command->quantity <= 0) {
            throw new DomainException('La cantidad del haber debe ser mayor que cero.');
        }

        $amount = $command->amount;
        $unitAmount = null;
        $conceptName = $command->concept;
        if ($concept !== null) {
            $unitAmount = (float) $concept['importe_unitario'];
            if (! $concept['permite_editar_importe']) {
                $amount = round($command->quantity * $unitAmount, 4);
            }
            if (trim($conceptName) === '') {
                $conceptName = $concept['nombre'];
            }
        }

        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds, $concept, $amount, $unitAmount, $conceptName): void {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se pueden editar haberes en un viaje cerrado o cancelado.');
            }
            if (! in_array($command->employeeId, $trip->driverIds(), true)) {
                throw new DomainException('El chofer elegido no está asignado al viaje.');
            }
            if ($this->costs->logbookEntryIdForEarning($trip->id() ?? 0, $companyId, $command->movementId) !== null) {
                throw new DomainException('Este haber se genera desde el diario de bordo. Corregilo desde esa etapa.');
            }

            $current = $this->costs->findOtherEarning($trip->id() ?? 0, $companyId, $command->movementId);
            if ($current === null) {
                throw new DomainException('El haber no existe en este viaje o ya fue quitado.');
            }

            $resolved = TripMoneyRules::resolveForUpdate(
                $this->directory,
                $companyId,
                $amount,
                $command->currency,
                $command->exchangeRate,
                $command->confirmForeignRateOne,
                [
                    'importe' => (float) ($current['importe'] ?? 0),
                    'moneda' => (string) ($current['moneda'] ?? 'ARS'),
                    'cotizacion' => (float) ($current['cotizacion'] ?? 1),
                ],
            );

            $earning = new TripOtherEarning(
                $command->employeeId,
                $command->date,
                $resolved['money'],
                $conceptName,
                $command->notes,
                $concept['id'] ?? null,
                $command->quantity,
                $unitAmount,
            );

            if ($resolved['changed']) {
                $reason = trim((string) $command->changeReason);
                if ($reason === '') {
                    throw new DomainException('Indicá el motivo del cambio monetario.');
                }
            }

            if (! $this->costs->updateOtherEarning($trip->id() ?? 0, $companyId, $command->movementId, $earning, $actor->userId(), null, $command->changeReason)) {
                throw new DomainException('El haber no existe en este viaje o ya fue quitado.');
            }
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para editar haberes de viajes.');
        }

        return $companyId;
    }
}
