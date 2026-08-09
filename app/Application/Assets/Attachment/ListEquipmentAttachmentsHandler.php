<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

final class ListEquipmentAttachmentsHandler
{
    public function __construct(private readonly EquipmentAttachmentReadModel $readModel)
    {
    }

    public function execute(ActorContext $actor, ListEquipmentAttachmentsQuery $query): EquipmentAttachmentPage
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La consulta requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar adjuntos de equipos.');
        }
        if ($query->page <= 0 || $query->perPage <= 0 || $query->perPage > 100) {
            throw new DomainException('La paginación de adjuntos no es válida.');
        }

        return $this->readModel->forEquipment(
            $actor->companyId(),
            $query->equipmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $query->page,
            $query->perPage,
        );
    }
}
