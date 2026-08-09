<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentRepository;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentStorage;
use App\Application\Identity\ActorContext;
use DomainException;

final class DownloadEquipmentAttachmentHandler
{
    public function __construct(
        private readonly EquipmentAttachmentRepository $attachments,
        private readonly EquipmentAttachmentStorage $storage,
    ) {
    }

    public function execute(
        ActorContext $actor,
        DownloadEquipmentAttachmentQuery $query,
    ): DownloadedEquipmentAttachment {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La descarga requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para descargar adjuntos de equipos.');
        }

        $attachment = $this->attachments->findActiveScoped(
            $actor->companyId(),
            $query->equipmentId,
            $query->attachmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
        if ($attachment === null) {
            throw new DomainException('El adjunto no existe, fue retirado o no está autorizado para el actor.');
        }

        return new DownloadedEquipmentAttachment(
            $attachment->originalName(),
            $attachment->mimeType(),
            $attachment->size(),
            $this->storage->read($attachment->privateRelativePath()),
        );
    }
}
