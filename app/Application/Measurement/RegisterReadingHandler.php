<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Identity\ActorContext;
use App\Application\Measurement\Port\LaterReadingLookup;
use App\Application\Measurement\Port\ReadingRepository;
use App\Application\Measurement\Port\UnitOfWork;
use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;
use DomainException;

final class RegisterReadingHandler
{
    public function __construct(
        private readonly EquipmentRepository $equipment,
        private readonly ReadingRepository $readings,
        private readonly UnitOfWork $unitOfWork,
        private readonly ?LaterReadingLookup $laterReadings = null,
    ) {
    }

    public function execute(ActorContext $actor, RegisterReadingCommand $command): RegisterReadingResult
    {
        $companyId = $this->tenantCompany($actor, $command);

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): RegisterReadingResult {
            $equipment = $this->equipment->findForUpdate($command->equipmentId, $companyId);
            if ($equipment === null || ! $actor->canAccessBranch($companyId, $equipment->branchId())) {
                throw new DomainException('El equipo no existe o no está autorizado para el actor.');
            }

            $measurement = UsageMeasurement::from($command->kilometers, $command->hours);
            // El cierre de un viaje viejo puede traer un km menor al actual porque el
            // camión ya hizo otro viaje: es una lectura histórica, no un retroceso.
            // Solo para lecturas de viaje y solo si una lectura posterior la cubre.
            $historical = $command->origin === EquipmentReading::TRIP
                && $this->laterReadings !== null
                && $equipment->isBelowCurrentKilometers($measurement);
            if ($historical) {
                $equipment->recordHistoricalUsage(
                    $measurement,
                    $this->laterReadings->hasLaterReadingAtOrAbove(
                        $companyId,
                        $command->equipmentId,
                        $command->recordedAt,
                        (int) $measurement->kilometers(),
                    ),
                );
                $correction = false;
            } else {
                $correction = $equipment->recordUsage(
                    $measurement,
                    $actor->hasPermission('lecturas.corregir'),
                    $command->correctionReason,
                );
            }
            $notes = $historical
                ? trim(($command->notes ?? '') . ' Lectura histórica: es anterior a una lectura mayor del equipo y no modifica el odómetro actual.')
                : $command->notes;

            $reading = EquipmentReading::record(
                $companyId,
                $equipment->branchId(),
                $command->equipmentId,
                $command->recordedAt,
                $measurement,
                $command->origin,
                $command->originReference,
                $actor->userId(),
                $correction,
                $command->correctionReason,
                $notes,
            );
            $readingId = $this->readings->append($reading);
            if (! $historical) {
                $this->equipment->updateCurrentUsage($equipment, $actor->userId());
            }

            return new RegisterReadingResult(
                $readingId,
                $command->equipmentId,
                $companyId,
                $equipment->branchId(),
                $equipment->currentKilometers(),
                $equipment->currentHours(),
                $correction,
            );
        });
    }

    private function tenantCompany(ActorContext $actor, RegisterReadingCommand $command): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }

        $canLoadReading = $actor->hasPermission('lecturas.cargar');
        $canRecordWorkOrderReading = $command->origin === EquipmentReading::WORK_ORDER
            && ($actor->hasPermission('ordenes.cerrar') || $actor->hasPermission('ordenes.editar'));
        $canRecordTripReading = $command->origin === EquipmentReading::TRIP
            && $actor->hasPermission('viajes.operar');
        if (! $canLoadReading && ! $canRecordWorkOrderReading && ! $canRecordTripReading) {
            throw new DomainException('No tenés permiso para cargar lecturas.');
        }

        return $actor->companyId();
    }
}
