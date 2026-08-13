<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

final readonly class ListPrimaryEquipmentPhotos
{
    public function __construct(private PrimaryEquipmentPhotoReadModel $photos)
    {
    }

    /** @param list<int> $equipmentIds @return array<int,array{attachmentId:int,equipmentId:int,hasThumbnail:bool}> */
    public function execute(ActorContext $actor, array $equipmentIds): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar fotos de equipos.');
        }

        return $this->photos->forEquipmentIds(
            $actor->companyId(),
            $equipmentIds,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
    }
}
