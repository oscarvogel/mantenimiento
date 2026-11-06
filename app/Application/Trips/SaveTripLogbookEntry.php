<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripRepository;
use App\Application\Trips\Port\TripCostRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;
use App\Application\Trips\Port\TripLogbookRepository;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripLogbookEntry;
use App\Domain\Trips\TripMoney;
use App\Domain\Trips\TripOtherEarning;
use DomainException;

final class SaveTripLogbookEntry
{
    public function __construct(
        private readonly OperationalTripRepository $trips,
        private readonly TripLogbookRepository $logbook,
        private readonly TripCostRepository $costs,
        private readonly TripFleetLookup $fleet,
        private readonly TripDirectoryLookup $directory,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, SaveTripLogbookEntryCommand $command): int
    {
        $companyId = $this->company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $driver = $this->fleet->driver($companyId, $command->employeeId);
        if ($driver === null || ! $driver['active']) {
            throw new DomainException('El chofer elegido no existe o está inactivo.');
        }

        $concept = $this->directory->concept($companyId, $command->conceptId);
        if ($concept === null) {
            throw new DomainException('El concepto de estadía elegido no está disponible.');
        }
        if (! in_array($concept['categoria'], ['HABER', 'AMBOS'], true)) {
            throw new DomainException('El concepto elegido no aplica a haberes.');
        }
        if ($concept['unidad'] !== 'DIA') {
            throw new DomainException('El concepto de estadía debe liquidarse por día.');
        }
        $unitAmount = (float) $concept['importe_unitario'];
        if ($unitAmount <= 0) {
            throw new DomainException('El concepto de estadía no tiene importe unitario.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $branchIds, $concept, $unitAmount): int {
            $trip = $this->trips->findForUpdate($companyId, $branchIds, $command->tripId);
            if ($trip === null) {
                throw new DomainException('El viaje solicitado no existe o no está autorizado.');
            }
            if (! in_array($trip->status(), [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
                throw new DomainException('No se puede editar el diario de bordo de un viaje cerrado o cancelado.');
            }
            if (! in_array($command->employeeId, $trip->driverIds(), true)) {
                throw new DomainException('El chofer elegido no está asignado al viaje.');
            }

            $tripId = $trip->id() ?? 0;
            $entryId = $command->entryId;
            $order = $entryId === null ? $this->logbook->nextOrder($tripId, $companyId) : 1;

            $entry = new TripLogbookEntry(
                $order,
                $command->type,
                $command->location,
                $command->arrivedAt,
                $command->departedAt,
                $command->freeHours,
                $command->employeeId,
                $command->conceptId,
                $command->adjustedDays,
                $command->adjustmentReason,
                $command->notes,
            );

            if ($entryId === null) {
                $entryId = $this->logbook->add($tripId, $companyId, $trip->branchId(), $entry, $actor->userId());
            } elseif (! $this->logbook->update($tripId, $companyId, $entryId, $entry, $actor->userId())) {
                throw new DomainException('La etapa del diario de bordo no existe en este viaje o ya fue quitada.');
            }

            $days = $entry->liquidatedDays() ?? 0.0;
            $linkedEarningId = $this->logbook->linkedEarningId($tripId, $companyId, $entryId);

            if ($days > 0) {
                $amount = round($days * $unitAmount, 4);
                $earning = new TripOtherEarning(
                    $command->employeeId,
                    $entry->departedAt() ?? $entry->arrivedAt(),
                    new TripMoney($amount, $concept['moneda'], 1.0),
                    $concept['nombre'],
                    $command->notes,
                    $concept['id'],
                    $days,
                    $unitAmount,
                );

                if ($linkedEarningId === null) {
                    $this->costs->addOtherEarning($tripId, $companyId, $trip->branchId(), $earning, $actor->userId(), $entryId);
                } else {
                    $this->costs->updateOtherEarning($tripId, $companyId, $linkedEarningId, $earning, $actor->userId(), $entryId);
                }
            } elseif ($linkedEarningId !== null) {
                $this->costs->softDeleteOtherEarning($tripId, $companyId, $linkedEarningId, $actor->userId());
            }

            return $entryId;
        });
    }

    private function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para cargar el diario de bordo de viajes.');
        }

        return $companyId;
    }
}
