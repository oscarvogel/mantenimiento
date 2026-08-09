<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\EquipmentLifecycleRepository;
use App\Application\Assets\Port\BrandRepository;
use App\Application\Assets\Port\EquipmentModelRepository;
use App\Domain\Assets\Equipment;
use App\Application\Identity\ActorContext;
use DomainException;

final class UpdateEquipmentHandler
{
    public function __construct(
        private readonly EquipmentLifecycleRepository $equipment,
        private readonly AssetUnitOfWork $unitOfWork,
        private readonly ?BrandRepository $brands = null,
        private readonly ?EquipmentModelRepository $models = null,
    ) {
    }

    public function execute(ActorContext $actor, UpdateEquipmentCommand $command): EquipmentMutationResult
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

            $previousBrandId = $equipment->brandId();
            $previousModelId = $equipment->modelId();
            $equipment->updateProfile(
                $command->code,
                $command->plate,
                $command->notes,
                $command->brandId,
                $command->modelId,
                $command->year,
                $command->chassis,
                $command->engine,
            );
            $this->assertTechnicalCompatibility($companyId, $equipment, $previousBrandId, $previousModelId);
            if ($this->equipment->codeExistsExcluding($companyId, $equipment->code(), $command->equipmentId)) {
                throw new DomainException('Ya existe un equipo con ese código en la empresa.');
            }

            $this->equipment->updateProfile($equipment, $actor->userId());

            return new EquipmentMutationResult(
                $command->equipmentId,
                $companyId,
                $equipment->branchId(),
                $equipment->code(),
                $equipment->status(),
            );
        });
    }

    private function assertTechnicalCompatibility(
        int $companyId,
        Equipment $equipment,
        ?int $previousBrandId,
        ?int $previousModelId,
    ): void
    {
        if ($equipment->brandId() === null) {
            return;
        }
        if ($equipment->brandId() === $previousBrandId && $equipment->modelId() === $previousModelId) {
            return;
        }
        if ($this->brands === null || $this->brands->findActiveById($companyId, $equipment->brandId()) === null) {
            throw new DomainException('La marca no existe o está inactiva en la empresa.');
        }
        if ($equipment->modelId() === null) {
            return;
        }
        $model = $this->models?->findActiveById($companyId, $equipment->modelId());
        if ($model === null) {
            throw new DomainException('El modelo no existe o está inactivo en la empresa.');
        }
        $model->assertCompatible($equipment->brandId(), $equipment->type()->id());
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para editar equipos.');
        }

        return $actor->companyId();
    }

    /** @return list<int>|null */
    private function branchScope(ActorContext $actor): ?array
    {
        return $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
    }
}
