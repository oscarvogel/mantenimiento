<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentClock;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentRepository;
use App\Application\Identity\ActorContext;
use DomainException;

final class RetireEquipmentAttachmentHandler
{
    public function __construct(
        private readonly EquipmentAttachmentRepository $attachments,
        private readonly EquipmentAttachmentClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, RetireEquipmentAttachmentCommand $command): void
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para retirar adjuntos de equipos.');
        }

        $attachment = $this->attachments->findActiveScoped(
            $actor->companyId(),
            $command->equipmentId,
            $command->attachmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
        if ($attachment === null) {
            throw new DomainException('El adjunto no existe, ya fue retirado o no está autorizado para el actor.');
        }

        $attachment->retire($actor->userId(), $this->clock->now(), $command->reason);
        $this->attachments->saveRetirement($attachment);
    }
}
