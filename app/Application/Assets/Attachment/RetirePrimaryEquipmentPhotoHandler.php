<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentClock;
use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoRepository;
use App\Application\Identity\ActorContext;
use DomainException;

final readonly class RetirePrimaryEquipmentPhotoHandler
{
    public function __construct(
        private PrimaryEquipmentPhotoRepository $photos,
        private EquipmentAttachmentClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, int $equipmentId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new DomainException('El motivo es obligatorio y admite hasta 255 caracteres.');
        }
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para retirar la foto principal.');
        }
        $retired = $this->photos->retire(
            $actor->companyId(),
            $equipmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $actor->userId(),
            $this->clock->now(),
            $reason,
        );
        if (! $retired) {
            throw new DomainException('El equipo no posee una foto principal activa dentro del alcance autorizado.');
        }
    }
}
