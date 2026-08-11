<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\EquipmentReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

final readonly class ListAvailableAssetBranches
{
    public function __construct(private EquipmentReadModel $readModel) {}

    /** @return list<array{id:int, codigo:string, nombre:string}> */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La consulta de sucursales requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar sucursales de equipos.');
        }

        return $this->readModel->listAvailableBranches(
            $actor->companyId(),
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
    }
}
