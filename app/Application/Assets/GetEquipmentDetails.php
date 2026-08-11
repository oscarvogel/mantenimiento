<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\EquipmentReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

final class GetEquipmentDetails
{
    public function __construct(private readonly EquipmentReadModel $readModel)
    {
    }

    /** @return array<string, mixed> */
    public function execute(
        ActorContext $actor,
        int $equipmentId,
        int $transferPage = 1,
        int $transfersPerPage = 10,
        int $relationPage = 1,
        int $relationsPerPage = 10,
    ): array {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La consulta requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar equipos.');
        }
        if ($transferPage <= 0 || $transfersPerPage <= 0 || $transfersPerPage > 100
            || $relationPage <= 0 || $relationsPerPage <= 0 || $relationsPerPage > 100) {
            throw new DomainException('La paginación del historial del equipo no es válida.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $details = $this->readModel->findDetails(
            $actor->companyId(),
            $equipmentId,
            $branchIds,
            $transferPage,
            $transfersPerPage,
            $relationPage,
            $relationsPerPage,
        );
        if ($details === null) {
            throw new DomainException('El equipo no existe o no está autorizado para el actor.');
        }

        $details['availableBranches'] = $this->readModel->listAvailableBranches($actor->companyId(), $branchIds);

        return $details;
    }
}
