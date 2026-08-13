<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use DomainException;

final readonly class ActualizarPlan
{
    public function __construct(
        private PlanMantenimientoRepository $plans,
        private PreventiveAssetGateway $assets,
    ) {
    }

    public function execute(ActualizarPlanCommand $command): int
    {
        if (! $command->actor->hasPermission('planes.editar')) {
            throw new DomainException('No tiene permiso para modificar planes de mantenimiento.');
        }

        if (! $command->actor->canAccessCompany($command->companyId) || $command->actor->isSuperAdmin()) {
            throw new DomainException('El plan solicitado queda fuera del alcance empresarial del usuario.');
        }

        $branchScope = $this->branchScope($command->actor->toArray());
        $plan        = $this->plans->findScoped($command->companyId, $command->planId, $branchScope);

        if ($plan === null) {
            throw new DomainException('El plan no existe o queda fuera del alcance autorizado.');
        }

        $equipment = $this->assets->findScoped($command->companyId, $plan->equipoId(), $branchScope);

        if ($equipment === null || ! $equipment->active) {
            throw new DomainException('El equipo del plan no existe o esta inactivo.');
        }

        if (! $command->actor->canAccessBranch($command->companyId, $equipment->branchId)) {
            throw new DomainException('La sucursal del equipo no esta autorizada.');
        }

        if ($command->intervalKm !== null && ! $equipment->tracksKilometres) {
            throw new DomainException('El equipo no controla kilometraje.');
        }

        if ($command->intervalHoursTenths !== null && ! $equipment->tracksHours) {
            throw new DomainException('El equipo no controla horometro.');
        }

        $updated = PlanMantenimiento::reconfigurar(
            (int) $plan->id(),
            $plan->empresaId(),
            $plan->equipoId(),
            $plan->tipoServicioId(),
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
            $plan->origenPlantillaId(),
            $plan->origenPlantillaItemId(),
        );

        return $this->plans->save($updated, $command->actor->userId());
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