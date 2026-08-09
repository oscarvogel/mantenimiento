<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\AssetCatalogReadModel;
use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\BrandRepository;
use App\Application\Assets\Port\EquipmentModelRepository;
use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Brand;
use App\Domain\Assets\EquipmentModel;
use DomainException;

final class AssetCatalogService
{
    public function __construct(
        private readonly BrandRepository $brands,
        private readonly EquipmentModelRepository $models,
        private readonly EquipmentTypeCatalog $types,
        private readonly AssetCatalogReadModel $readModel,
        private readonly AssetUnitOfWork $unitOfWork,
    ) {}

    /** @return array{brands:list<array<string,mixed>>,models:list<array<string,mixed>>,types:list<array<string,mixed>>} */
    public function list(ActorContext $actor, bool $includeInactive = false): array
    {
        $companyId = $this->tenant($actor, 'equipos.ver');
        if ($includeInactive && ! $actor->hasPermission('equipos.editar')) {
            throw new DomainException('No tenés permiso para consultar catálogos inactivos.');
        }

        return $this->readModel->list($companyId, $includeInactive);
    }

    public function createBrand(ActorContext $actor, CreateBrandCommand $command): int
    {
        $companyId = $this->tenant($actor, 'equipos.editar');
        $brand = Brand::create($companyId, $command->name);
        if ($this->brands->nameExists($companyId, $brand->name())) {
            throw new DomainException('Ya existe una marca con ese nombre en la empresa.');
        }

        return $this->brands->add($brand, $actor->userId());
    }

    public function renameBrand(ActorContext $actor, RenameBrandCommand $command): void
    {
        $companyId = $this->tenant($actor, 'equipos.editar');
        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): void {
            $brand = $this->brands->findForUpdate($companyId, $command->brandId);
            if ($brand === null) {
                throw new DomainException('La marca no existe en la empresa.');
            }
            $brand->rename($command->name);
            if ($this->brands->nameExists($companyId, $brand->name(), $command->brandId)) {
                throw new DomainException('Ya existe una marca con ese nombre en la empresa.');
            }
            $this->brands->save($brand, $actor->userId());
        });
    }

    public function inactivateBrand(ActorContext $actor, InactivateBrandCommand $command): void
    {
        $companyId = $this->tenant($actor, 'equipos.editar');
        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): void {
            $brand = $this->brands->findForUpdate($companyId, $command->brandId);
            if ($brand === null) {
                throw new DomainException('La marca no existe en la empresa.');
            }
            if ($this->brands->hasActiveModels($companyId, $command->brandId)) {
                throw new DomainException('No se puede inactivar una marca con modelos activos.');
            }
            $brand->inactivate();
            $this->brands->save($brand, $actor->userId());
        });
    }

    public function createModel(ActorContext $actor, CreateEquipmentModelCommand $command): int
    {
        $companyId = $this->tenant($actor, 'equipos.editar');
        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): int {
            $brand = $this->brands->findForUpdate($companyId, $command->brandId);
            if ($brand === null || ! $brand->isActive() || $this->types->findActiveById($command->typeId) === null) {
                throw new DomainException('La marca o el tipo no existe o está inactivo.');
            }
            $model = EquipmentModel::create($companyId, $command->brandId, $command->typeId, $command->name);
            if ($this->models->nameExists($companyId, $command->brandId, $command->typeId, $model->name())) {
                throw new DomainException('Ya existe ese modelo para la marca y tipo seleccionados.');
            }

            return $this->models->add($model, $actor->userId());
        });
    }

    public function renameModel(ActorContext $actor, RenameEquipmentModelCommand $command): void
    {
        $companyId = $this->tenant($actor, 'equipos.editar');
        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): void {
            $model = $this->models->findForUpdate($companyId, $command->modelId);
            if ($model === null) {
                throw new DomainException('El modelo no existe en la empresa.');
            }
            $model->rename($command->name);
            if ($this->models->nameExists($companyId, $model->brandId(), $model->equipmentTypeId(), $model->name(), $command->modelId)) {
                throw new DomainException('Ya existe ese modelo para la marca y tipo seleccionados.');
            }
            $this->models->save($model, $actor->userId());
        });
    }

    public function inactivateModel(ActorContext $actor, InactivateEquipmentModelCommand $command): void
    {
        $companyId = $this->tenant($actor, 'equipos.editar');
        $this->unitOfWork->transactional(function () use ($actor, $command, $companyId): void {
            $model = $this->models->findForUpdate($companyId, $command->modelId);
            if ($model === null) {
                throw new DomainException('El modelo no existe en la empresa.');
            }
            $model->inactivate();
            $this->models->save($model, $actor->userId());
        });
    }

    private function tenant(ActorContext $actor, string $permission): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operación requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenés permiso para administrar catálogos de equipos.');
        }

        return $actor->companyId();
    }
}
