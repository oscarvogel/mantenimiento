<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentStorage;
use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoRepository;
use App\Application\Identity\ActorContext;
use DomainException;

final readonly class DownloadPrimaryEquipmentPhotoHandler
{
    public function __construct(
        private PrimaryEquipmentPhotoRepository $photos,
        private EquipmentAttachmentStorage $storage,
    ) {
    }

    public function execute(ActorContext $actor, int $equipmentId, bool $thumbnail): DownloadedEquipmentAttachment
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar la foto principal.');
        }
        $photo = $this->photos->findScoped(
            $actor->companyId(),
            $equipmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
        if ($photo === null) {
            throw new DomainException('El equipo no posee una foto principal activa.');
        }
        $useThumbnail = $thumbnail && $photo->thumbnailPath !== null;
        $path = $useThumbnail ? $photo->thumbnailPath : $photo->originalPath;
        $mime = $useThumbnail ? (string) $photo->thumbnailMimeType : $photo->originalMimeType;
        $size = $useThumbnail ? (int) $photo->thumbnailSize : $photo->originalSize;

        return new DownloadedEquipmentAttachment($photo->originalName, $mime, $size, $this->storage->read($path));
    }
}
