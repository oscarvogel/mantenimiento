<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoRepository;
use App\Application\Identity\ActorContext;
use DomainException;

final readonly class GetPrimaryEquipmentPhotoHandler
{
    public function __construct(private PrimaryEquipmentPhotoRepository $photos)
    {
    }

    public function execute(ActorContext $actor, int $equipmentId): ?PrimaryEquipmentPhoto
    {
        if ($equipmentId <= 0 || $actor->isSuperAdmin() || $actor->companyId() === null
            || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar la foto principal.');
        }

        return $this->photos->findScoped(
            $actor->companyId(),
            $equipmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
    }
}
