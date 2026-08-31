<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Identity\ActorContext;
use App\Application\Measurement\Port\ReadingCorrectionRepository;
use App\Application\Measurement\Port\ReadingRepository;
use App\Application\Measurement\Port\UnitOfWork;
use App\Application\Measurement\Port\WorkOrderReadingCorrectionSynchronizer;
use App\Domain\Measurement\UsageMeasurement;
use DomainException;

final class CorrectReadingHandler
{
    private readonly ?WorkOrderReadingCorrectionSynchronizer $workOrderSynchronizer;

    public function __construct(
        private readonly EquipmentRepository $equipment,
        private readonly ReadingRepository $readings,
        private readonly ReadingCorrectionRepository $corrections,
        private readonly UnitOfWork $unitOfWork,
        ?WorkOrderReadingCorrectionSynchronizer $workOrderSynchronizer = null,
    ) {
        $this->workOrderSynchronizer = $workOrderSynchronizer
            ?? ($corrections instanceof WorkOrderReadingCorrectionSynchronizer ? $corrections : null);
    }

    public function execute(ActorContext $actor, CorrectReadingCommand $command): CorrectReadingResult
    {
        $companyId = $this->tenantCompany($actor);
        if ($command->equipmentId <= 0 || $command->readingId <= 0) {
            throw new DomainException('El equipo y la lectura deben ser válidos.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): CorrectReadingResult {
            $equipment = $this->equipment->findForUpdate($command->equipmentId, $companyId);
            if ($equipment === null || ! $actor->canAccessBranch($companyId, $equipment->branchId())) {
                throw new DomainException('El equipo no existe o no está autorizado para el actor.');
            }

            $original = $this->corrections->findForUpdate($command->readingId, $companyId, $command->equipmentId);
            if ($original === null) {
                throw new DomainException('La lectura no existe o no está autorizada para el actor.');
            }

            $replacement = UsageMeasurement::from($command->kilometers, $command->hours);
            if ($replacement->hasKilometers() && ! $equipment->type()->tracksKilometers()) {
                throw new DomainException('El tipo de equipo no controla kilometraje.');
            }
            if ($replacement->hasHours() && ! $equipment->type()->tracksHours()) {
                throw new DomainException('El tipo de equipo no controla horómetro.');
            }

            $correction = $original->correct(
                $replacement,
                $actor->userId(),
                $command->reason,
                $command->notes,
                $command->correctedAt,
            );
            $correctionId = $this->readings->append($correction);
            $this->corrections->markAnnulled($original);
            $this->workOrderSynchronizer?->synchronizeFinalizedWorkOrder(
                $original,
                $replacement,
                $correctionId,
                $actor->userId(),
                $command->reason,
                $command->notes,
                $command->correctedAt,
            );
            $current = $this->corrections->recalculateCurrentUsage(
                $companyId,
                $equipment->branchId(),
                $command->equipmentId,
                $actor->userId(),
            );

            return new CorrectReadingResult(
                $command->readingId,
                $correctionId,
                $command->equipmentId,
                $companyId,
                $equipment->branchId(),
                $current->kilometers(),
                $current->hours(),
            );
        });
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('lecturas.corregir')) {
            throw new DomainException('No tenés permiso para corregir lecturas.');
        }

        return $actor->companyId();
    }
}
