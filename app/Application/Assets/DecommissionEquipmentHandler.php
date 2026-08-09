<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\EquipmentLifecycleRepository;
use App\Application\Assets\Port\EquipmentWorkStatus;
use App\Application\Assets\Port\EquipmentRelationStatus;
use App\Application\Identity\ActorContext;
use DomainException;

final class DecommissionEquipmentHandler
{
    public function __construct(
        private readonly EquipmentLifecycleRepository $equipment,
        private readonly EquipmentWorkStatus $workStatus,
        private readonly AssetUnitOfWork $unitOfWork,
        private readonly ?EquipmentRelationStatus $relationStatus = null,
    ) {
    }

    public function execute(ActorContext $actor, DecommissionEquipmentCommand $command): EquipmentMutationResult
    {
        $companyId = $this->tenantCompany($actor);

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): EquipmentMutationResult {
            $equipment = $this->equipment->findScopedForUpdate(
                $companyId,
                $command->equipmentId,
                $this->branchScope($actor),
            );
            if ($equipment === null) {
                throw new DomainException('El equipo no existe o no está autorizado para el actor.');
            }
            if ($this->workStatus->hasOpenOrders($companyId, $command->equipmentId)) {
                throw new DomainException('No se puede dar de baja un equipo con órdenes de trabajo abiertas.');
            }

            if ($this->relationStatus?->hasActiveRelations($companyId, $command->equipmentId)) {
                throw new DomainException('No se puede dar de baja un equipo con relaciones activas.');
            }

            $equipment->decommission($command->decommissionedAt);
            $this->equipment->decommission($equipment, $actor->userId());

            return new EquipmentMutationResult(
                $command->equipmentId,
                $companyId,
                $equipment->branchId(),
                $equipment->code(),
                $equipment->status(),
            );
        });
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para dar de baja equipos.');
        }

        return $actor->companyId();
    }

    /** @return list<int>|null */
    private function branchScope(ActorContext $actor): ?array
    {
        return $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
    }
}
