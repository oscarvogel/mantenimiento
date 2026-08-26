<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\EquipmentListReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

final class ListEquipment
{
    public function __construct(private readonly EquipmentListReadModel $readModel) {}

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,totalPages:int} */
    public function execute(ActorContext $actor, EquipmentListQuery $query): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar equipos de una empresa.');
        }
        if ($query->branchId !== null && ! $actor->canAccessBranch($actor->companyId(), $query->branchId)) {
            throw new DomainException('La sucursal filtrada no está autorizada para el actor.');
        }
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $result = $this->readModel->search(
            $actor->companyId(),
            $branchIds,
            ($value = trim((string) $query->query)) === '' ? null : $value,
            $query->typeId,
            $query->brandId,
            $query->branchId,
            $query->status,
            $query->page,
            $query->perPage,
        );

        return $result + [
            'page' => $query->page,
            'perPage' => $query->perPage,
            'totalPages' => max(1, (int) ceil($result['total'] / $query->perPage)),
        ];
    }
}
