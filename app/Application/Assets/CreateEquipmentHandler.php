<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\BranchScope;
use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Application\Assets\Port\BrandRepository;
use App\Application\Assets\Port\EquipmentModelRepository;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Equipment;
use DomainException;

final class CreateEquipmentHandler
{
    public function __construct(
        private readonly EquipmentRepository $equipment,
        private readonly EquipmentTypeCatalog $types,
        private readonly BranchScope $branches,
        private readonly ?BrandRepository $brands = null,
        private readonly ?EquipmentModelRepository $models = null,
    ) {
    }

    public function execute(ActorContext $actor, CreateEquipmentCommand $command): CreateEquipmentResult
    {
        $companyId = $this->tenantCompany($actor, 'equipos.editar');
        if (! $actor->canAccessBranch($companyId, $command->branchId)
            || ! $this->branches->isActiveInCompany($companyId, $command->branchId)) {
            throw new DomainException('La sucursal no existe o no está autorizada para el actor.');
        }

        $type = $this->types->findActiveById($command->typeId);
        if ($type === null) {
            throw new DomainException('El tipo de equipo no existe o está inactivo.');
        }

        $equipment = Equipment::create(
            $companyId,
            $command->branchId,
            $type,
            $command->code,
            $command->plate,
            $command->registeredAt,
            $command->notes,
            $command->brandId,
            $command->modelId,
            $command->year,
            $command->chassis,
            $command->engine,
        );
        $this->assertTechnicalCompatibility($companyId, $equipment);
        if ($this->equipment->codeExists($companyId, $equipment->code())) {
            throw new DomainException('Ya existe un equipo con ese código en la empresa.');
        }

        $equipmentId = $this->equipment->add($equipment, $actor->userId());

        return new CreateEquipmentResult(
            $equipmentId,
            $companyId,
            $equipment->branchId(),
            $equipment->code(),
        );
    }

    private function assertTechnicalCompatibility(int $companyId, Equipment $equipment): void
    {
        if ($equipment->brandId() === null) {
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

    private function tenantCompany(ActorContext $actor, string $permission): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenés permiso para realizar esta operación.');
        }

        return $actor->companyId();
    }
}
