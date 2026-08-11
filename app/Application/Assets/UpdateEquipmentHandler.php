<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\AssetClock;
use App\Application\Assets\Port\EquipmentLifecycleRepository;
use App\Application\Assets\Port\BrandRepository;
use App\Application\Assets\Port\EquipmentModelRepository;
use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Application\Assets\Port\EquipmentTypeChangeGuard;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use App\Application\Identity\ActorContext;
use DomainException;

final class UpdateEquipmentHandler
{
    public function __construct(
        private readonly EquipmentLifecycleRepository $equipment,
        private readonly AssetUnitOfWork $unitOfWork,
        private readonly ?BrandRepository $brands = null,
        private readonly ?EquipmentModelRepository $models = null,
        private readonly ?EquipmentTypeCatalog $types = null,
        private readonly ?AssetClock $clock = null,
        private readonly ?EquipmentTypeChangeGuard $typeChangeGuard = null,
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
            $previousTypeId = $equipment->type()->id();
            $type = $this->updatedType($companyId, $equipment, $command);
            $equipment->updateProfile(
                $command->code,
                $command->plate,
                $command->notes,
                $command->brandId,
                $command->modelId,
                $command->year,
                $command->chassis,
                $command->engine,
                $type,
                $command->registeredAt,
                $command->registeredAt === null ? null : $this->today(),
            );
            $this->assertTechnicalCompatibility($companyId, $equipment, $previousBrandId, $previousModelId, $previousTypeId);
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
        int $previousTypeId,
    ): void
    {
        if ($equipment->brandId() === null) {
            return;
        }
        if ($equipment->brandId() === $previousBrandId && $equipment->modelId() === $previousModelId && $equipment->type()->id() === $previousTypeId) {
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

    private function updatedType(int $companyId, Equipment $equipment, UpdateEquipmentCommand $command): ?EquipmentType
    {
        if ($command->typeId === null || $command->typeId === $equipment->type()->id()) {
            return null;
        }
        $type = $this->types?->findActiveById($command->typeId);
        if ($type === null) {
            throw new DomainException('El tipo de equipo no existe o está inactivo.');
        }
        if ($this->typeChangeGuard === null) {
            throw new DomainException('No se configuró la validación requerida para cambiar el tipo de equipo.');
        }
        if ($this->typeChangeGuard->hasOpenWorkOrders($companyId, $command->equipmentId)) {
            throw new DomainException('No se puede cambiar el tipo mientras el equipo tenga una orden de trabajo abierta.');
        }
        if (! $type->tracksKilometers() && $this->typeChangeGuard->hasActivePlanUsingKilometers($companyId, $command->equipmentId)) {
            throw new DomainException('No se puede cambiar a un tipo sin kilometraje porque existe un plan activo por kilómetros.');
        }
        if (! $type->tracksHours() && $this->typeChangeGuard->hasActivePlanUsingHours($companyId, $command->equipmentId)) {
            throw new DomainException('No se puede cambiar a un tipo sin horómetro porque existe un plan activo por horas.');
        }
        if (! $type->tracksKilometers() && $this->equipment->hasRecordedUsage($companyId, $command->equipmentId, 'kilometraje')) {
            throw new DomainException('No se puede cambiar a un tipo sin kilometraje porque existen lecturas históricas de kilometraje.');
        }
        if (! $type->tracksHours() && $this->equipment->hasRecordedUsage($companyId, $command->equipmentId, 'horometro')) {
            throw new DomainException('No se puede cambiar a un tipo sin horómetro porque existen lecturas históricas de horómetro.');
        }

        return $type;
    }

    private function today(): \DateTimeImmutable
    {
        if ($this->clock === null) {
            throw new DomainException('No se configuró el reloj requerido para modificar la fecha de alta.');
        }

        return $this->clock->today();
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
