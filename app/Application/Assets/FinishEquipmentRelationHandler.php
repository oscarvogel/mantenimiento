<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\EquipmentRelationRepository;
use App\Application\Identity\ActorContext;
use DomainException;

final class FinishEquipmentRelationHandler
{
    public function __construct(
        private readonly EquipmentRelationRepository $relations,
        private readonly AssetUnitOfWork $unitOfWork,
    ) {}

    public function execute(ActorContext $actor, FinishEquipmentRelationCommand $command): EquipmentRelationResult
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para administrar relaciones de equipos.');
        }
        return $this->unitOfWork->transactional(function () use ($actor, $command): EquipmentRelationResult {
            $scope = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
            $relation = $this->relations->findRelationForUpdate($actor->companyId(), $command->relationId, $scope);
            if ($relation === null) {
                throw new DomainException('La relación no existe o no está autorizada para el actor.');
            }
            $relation->finish($command->endedAt, $actor->userId(), $command->notes);
            $this->relations->finish($relation);

            return new EquipmentRelationResult(
                $command->relationId,
                $relation->principalEquipmentId(),
                $relation->relatedEquipmentId(),
                $relation->type(),
                false,
            );
        });
    }
}
