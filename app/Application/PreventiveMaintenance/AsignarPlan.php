<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use DomainException;

final readonly class AsignarPlan
{
    public function __construct(
        private PlanMantenimientoRepository $plans,
        private PreventiveAssetGateway $assets,
        private ServiceTypeGateway $serviceTypes,
        private Clock $clock,
    ) {
    }

    public function execute(AsignarPlanCommand $command): int
    {
        if (! $command->actor->hasPermission('planes.editar')) {
            throw new DomainException('No tiene permiso para asignar planes de mantenimiento.');
        }

        if (! $command->actor->canAccessCompany($command->companyId) || $command->actor->isSuperAdmin()) {
            throw new DomainException('El plan solicitado queda fuera del alcance empresarial del usuario.');
        }

        $branchScope = $this->branchScope($command->actor->toArray());
        $equipment   = $this->assets->findScoped($command->companyId, $command->equipmentId, $branchScope);

        if ($equipment === null || ! $equipment->active) {
            throw new DomainException('El equipo no existe, esta inactivo o queda fuera del alcance autorizado.');
        }

        if (! $command->actor->canAccessBranch($command->companyId, $equipment->branchId)) {
            throw new DomainException('La sucursal del equipo no esta autorizada.');
        }

        if (! $this->serviceTypes->isActive($command->serviceTypeId)) {
            throw new DomainException('El tipo de servicio no existe o esta inactivo.');
        }

        if ($command->intervalKm !== null && ! $equipment->tracksKilometres) {
            throw new DomainException('El equipo no controla kilometraje.');
        }

        if ($command->intervalHoursTenths !== null && ! $equipment->tracksHours) {
            throw new DomainException('El equipo no controla horometro.');
        }

        if ($this->plans->existsActive(
            $command->companyId,
            $command->equipmentId,
            $command->serviceTypeId,
            $branchScope,
        )) {
            throw new DomainException('Ya existe un plan activo para el equipo y tipo de servicio.');
        }

        $plan = PlanMantenimiento::asignar(
            $command->companyId,
            $command->equipmentId,
            $command->serviceTypeId,
            $command->intervalKm,
            $command->intervalHoursTenths,
            $command->intervalDays,
            $command->warningKm,
            $command->warningHoursTenths,
            $command->warningDays,
            $command->intervalKm === null ? null : $command->baseKm,
            $command->intervalHoursTenths === null ? null : $command->baseHoursTenths,
            $command->intervalDays === null ? null : $command->baseDate,
            $command->priority,
            $command->notes,
        );

        return $this->plans->save($plan, $command->actor->userId());
    }

    /**
     * @param array{all_company_branches: bool, branch_ids: list<int>} $actorData
     * @return list<int>|null
     */
    private function branchScope(array $actorData): ?array
    {
        return $actorData['all_company_branches'] ? null : $actorData['branch_ids'];
    }
}
