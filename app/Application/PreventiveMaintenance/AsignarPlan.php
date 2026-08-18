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
            throw new DomainException('No tiene permiso para asignar servicios de mantenimiento.');
        }

        if (! $command->actor->canAccessCompany($command->companyId) || $command->actor->isSuperAdmin()) {
            throw new DomainException('La asignación solicitada queda fuera del alcance empresarial del usuario.');
        }

        $branchScope = $this->branchScope($command->actor->toArray());
        $equipment   = $this->assets->findScoped($command->companyId, $command->equipmentId, $branchScope);

        if ($equipment === null || ! $equipment->active) {
            throw new DomainException('El equipo no existe, está inactivo o queda fuera del alcance autorizado.');
        }

        if (! $command->actor->canAccessBranch($command->companyId, $equipment->branchId)) {
            throw new DomainException('La sucursal del equipo no está autorizada.');
        }

        $service = $this->serviceTypes->findActiveDefinition($command->companyId, $command->serviceTypeId);
        if ($service === null) {
            throw new DomainException('El servicio no existe, está inactivo o pertenece a otra empresa.');
        }

        if ($service['intervalKm'] !== null && ! $equipment->tracksKilometres) {
            throw new DomainException('Este servicio controla kilometraje y el equipo no registra kilómetros.');
        }

        if ($service['intervalHoursTenths'] !== null && ! $equipment->tracksHours) {
            throw new DomainException('Este servicio controla horómetro y el equipo no registra horas.');
        }

        if ($this->plans->existsActive(
            $command->companyId,
            $command->equipmentId,
            $command->serviceTypeId,
            $branchScope,
        )) {
            throw new DomainException('Este servicio ya está asignado al equipo.');
        }

        $plan = PlanMantenimiento::asignar(
            $command->companyId,
            $command->equipmentId,
            $command->serviceTypeId,
            $service['intervalKm'],
            $service['intervalHoursTenths'],
            $service['intervalDays'],
            $service['warningKm'],
            $service['warningHoursTenths'],
            $service['warningDays'],
            $service['intervalKm'] === null ? null : $command->baseKm,
            $service['intervalHoursTenths'] === null ? null : $command->baseHoursTenths,
            $service['intervalDays'] === null ? null : $command->baseDate,
            $service['priority'],
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
