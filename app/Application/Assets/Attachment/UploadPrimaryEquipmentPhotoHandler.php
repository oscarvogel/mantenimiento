<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentClock;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentEquipmentScope;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentFileInspector;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentStorage;
use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoRepository;
use App\Application\Assets\Attachment\Port\PrimaryPhotoProcessor;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\EquipmentAttachment;
use DomainException;
use Throwable;

final readonly class UploadPrimaryEquipmentPhotoHandler
{
    /** @var list<string> */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private EquipmentAttachmentFileInspector $inspector,
        private EquipmentAttachmentStorage $storage,
        private EquipmentAttachmentEquipmentScope $equipmentScope,
        private EquipmentAttachmentClock $clock,
        private PrimaryEquipmentPhotoRepository $photos,
        private PrimaryPhotoProcessor $processor,
        private int $maximumSizeBytes = 5_242_880,
    ) {
        if ($maximumSizeBytes <= 0) {
            throw new DomainException('El tamaño máximo configurado para la foto principal no es válido.');
        }
    }

    public function execute(ActorContext $actor, UploadPrimaryEquipmentPhotoCommand $command): int
    {
        [$companyId, $branchIds] = $this->scope($actor);
        $branchId = $this->equipmentScope->currentBranchId($companyId, $command->equipmentId, $branchIds);
        if ($branchId === null) {
            throw new DomainException('El equipo no existe o no está autorizado para el actor.');
        }

        $inspected = $this->inspector->inspect($command->temporaryPath);
        if (! in_array($inspected->mimeType, self::IMAGE_MIMES, true)) {
            throw new DomainException('La foto principal debe ser JPG, PNG o WEBP.');
        }
        EquipmentAttachment::assertUpload($command->originalName, $inspected->mimeType, $inspected->size, $this->maximumSizeBytes);

        $original = $this->storage->store(
            $command->temporaryPath,
            $companyId,
            EquipmentAttachment::canonicalExtension($inspected->mimeType),
        );
        $thumbnail = null;
        $processed = null;
        try {
            $processed = $this->processor->createThumbnail($command->temporaryPath, $inspected->mimeType);
            if ($processed !== null) {
                $thumbnail = $this->storage->store($processed->temporaryPath, $companyId, $processed->extension);
            }
            $photo = EquipmentAttachment::register(
                $companyId,
                $command->equipmentId,
                $branchId,
                'FOTO_PRINCIPAL',
                $command->originalName,
                $original->storedName,
                $original->privateRelativePath,
                $inspected->mimeType,
                $inspected->size,
                $this->maximumSizeBytes,
                $command->description,
                $actor->userId(),
                $this->clock->now(),
            );

            return $this->photos->replace(
                $photo,
                $thumbnail,
                $processed?->mimeType,
                $processed?->size,
            );
        } catch (Throwable $exception) {
            $this->bestEffortDelete($original->privateRelativePath);
            if ($thumbnail !== null) {
                $this->bestEffortDelete($thumbnail->privateRelativePath);
            }
            throw $exception;
        } finally {
            if ($processed !== null && is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
            }
        }
    }

    /** @return array{0:int,1:list<int>|null} */
    private function scope(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para administrar la foto principal del equipo.');
        }

        return [$actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds()];
    }

    private function bestEffortDelete(string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (Throwable) {
        }
    }
}
