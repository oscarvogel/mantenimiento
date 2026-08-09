<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\EquipmentRelationRepository;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentRelation;
use DomainException;

final class CreateEquipmentRelationHandler
{
    public function __construct(
        private readonly EquipmentRelationRepository $relations,
        private readonly AssetUnitOfWork $unitOfWork,
    ) {}

    public function execute(ActorContext $actor, CreateEquipmentRelationCommand $command): EquipmentRelationResult
    {
        $companyId = $this->tenant($actor);

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): EquipmentRelationResult {
            $scope = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
            $firstId = min($command->principalEquipmentId, $command->relatedEquipmentId);
            $secondId = max($command->principalEquipmentId, $command->relatedEquipmentId);
            $first = $this->relations->findEquipmentForUpdate($companyId, $firstId, $scope);
            $second = $this->relations->findEquipmentForUpdate($companyId, $secondId, $scope);
            $principal = $firstId === $command->principalEquipmentId ? $first : $second;
            $related = $firstId === $command->relatedEquipmentId ? $first : $second;
            if ($principal === null || $related === null) {
                throw new DomainException('Uno de los equipos no existe o no está autorizado para el actor.');
            }
            if ($principal->status() !== Equipment::ACTIVE || $related->status() !== Equipment::ACTIVE) {
                throw new DomainException('Solo se pueden relacionar equipos activos.');
            }
            if ($command->startedAt < $principal->registeredAt() || $command->startedAt < $related->registeredAt()) {
                throw new DomainException('La relación no puede comenzar antes del alta de alguno de los equipos.');
            }
            $relation = EquipmentRelation::start(
                $companyId,
                $command->principalEquipmentId,
                $command->relatedEquipmentId,
                $command->type,
                $command->startedAt,
                $actor->userId(),
                $command->notes,
            );
            if ($relation->isIncompatibleWithActiveRelation()
                && $this->relations->hasActiveIncompatibleRelation($companyId, $relation->relatedEquipmentId(), $relation->type())) {
                throw new DomainException('El equipo relacionado ya posee una relación activa incompatible.');
            }
            $relationId = $this->relations->add($relation);

            return new EquipmentRelationResult($relationId, $relation->principalEquipmentId(), $relation->relatedEquipmentId(), $relation->type(), true);
        });
    }

    private function tenant(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para administrar relaciones de equipos.');
        }
        return $actor->companyId();
    }
}
