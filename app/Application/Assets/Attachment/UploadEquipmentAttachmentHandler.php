<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentClock;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentEquipmentScope;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentFileInspector;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentRepository;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentStorage;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\EquipmentAttachment;
use DomainException;
use Throwable;

final class UploadEquipmentAttachmentHandler
{
    public function __construct(
        private readonly EquipmentAttachmentFileInspector $inspector,
        private readonly EquipmentAttachmentStorage $storage,
        private readonly EquipmentAttachmentRepository $attachments,
        private readonly EquipmentAttachmentEquipmentScope $equipmentScope,
        private readonly EquipmentAttachmentClock $clock,
        private readonly int $maximumSizeBytes = 10_485_760,
    ) {
        if ($maximumSizeBytes <= 0) {
            throw new DomainException('El tamaño máximo configurado para adjuntos no es válido.');
        }
    }

    public function execute(ActorContext $actor, UploadEquipmentAttachmentCommand $command): int
    {
        [$companyId, $branchIds] = $this->tenantScope($actor, 'equipos.editar');
        $branchId = $this->equipmentScope->currentBranchId($companyId, $command->equipmentId, $branchIds);
        if ($branchId === null) {
            throw new DomainException('El equipo no existe o no está autorizado para el actor.');
        }

        $inspected = $this->inspector->inspect($command->temporaryPath);
        EquipmentAttachment::assertUpload(
            $command->originalName,
            $inspected->mimeType,
            $inspected->size,
            $this->maximumSizeBytes,
        );
        $extension = EquipmentAttachment::canonicalExtension($inspected->mimeType);
        $stored = $this->storage->store($command->temporaryPath, $companyId, $extension);

        try {
            $attachment = EquipmentAttachment::register(
                $companyId,
                $command->equipmentId,
                $branchId,
                $command->type,
                $command->originalName,
                $stored->storedName,
                $stored->privateRelativePath,
                $inspected->mimeType,
                $inspected->size,
                $this->maximumSizeBytes,
                $command->description,
                $actor->userId(),
                $this->clock->now(),
            );

            return $this->attachments->add($attachment);
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($stored->privateRelativePath);
            } catch (Throwable) {
                // Preserve the persistence error; orphan cleanup can be retried operationally.
            }
            throw $exception;
        }
    }

    /** @return array{0:int, 1:list<int>|null} */
    private function tenantScope(ActorContext $actor, string $permission): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenés permiso para realizar esta operación.');
        }

        return [$actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds()];
    }
}
