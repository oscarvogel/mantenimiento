<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\BranchScope;
use App\Application\Assets\Port\EquipmentLifecycleRepository;
use App\Application\Identity\ActorContext;
use DomainException;

final class TransferEquipmentHandler
{
    public function __construct(
        private readonly EquipmentLifecycleRepository $equipment,
        private readonly BranchScope $branches,
        private readonly AssetUnitOfWork $unitOfWork,
    ) {
    }

    public function execute(ActorContext $actor, TransferEquipmentCommand $command): EquipmentMutationResult
    {
        $companyId = $this->tenantCompany($actor);
        $reason = trim($command->reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new DomainException('El traslado requiere un motivo de entre 5 y 255 caracteres.');
        }
        if (! $actor->canAccessBranch($companyId, $command->destinationBranchId)) {
            throw new DomainException('La sucursal de destino no existe, está inactiva o no está autorizada.');
        }

        return $this->unitOfWork->transactional(function () use ($actor, $command, $companyId, $reason): EquipmentMutationResult {
            if (! $this->branches->isActiveInCompany($companyId, $command->destinationBranchId)) {
                throw new DomainException('La sucursal de destino no existe, está inactiva o no está autorizada.');
            }

            $equipment = $this->equipment->findScopedForUpdate(
                $companyId,
                $command->equipmentId,
                $this->branchScope($actor),
            );
            if ($equipment === null) {
                throw new DomainException('El equipo no existe o no está autorizado para el actor.');
            }

            $latestTransferAt = $this->equipment->latestTransferAtForUpdate($companyId, $command->equipmentId);
            if ($latestTransferAt !== null && $command->occurredAt <= $latestTransferAt) {
                throw new DomainException('La fecha del traslado debe ser posterior al último movimiento registrado.');
            }

            $originBranchId = $equipment->transferTo($command->destinationBranchId, $command->occurredAt);
            $this->equipment->transfer(
                $equipment,
                $originBranchId,
                $command->occurredAt,
                $reason,
                $actor->userId(),
            );

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
            throw new DomainException('No tenés permiso para trasladar equipos.');
        }

        return $actor->companyId();
    }

    /** @return list<int>|null */
    private function branchScope(ActorContext $actor): ?array
    {
        return $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
    }
}
