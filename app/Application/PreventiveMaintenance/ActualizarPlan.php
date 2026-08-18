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
            throw new DomainException('No tiene permiso para modificar asignaciones de mantenimiento.');
        }

        if (! $command->actor->canAccessCompany($command->companyId) || $command->actor->isSuperAdmin()) {
            throw new DomainException('La asignación solicitada queda fuera del alcance empresarial del usuario.');
        }

        $branchScope = $this->branchScope($command->actor->toArray());
        $plan = $this->plans->findScoped($command->companyId, $command->planId, $branchScope);

        if ($plan === null) {
            throw new DomainException('La asignación no existe o queda fuera del alcance autorizado.');
        }

        $equipment = $this->assets->findScoped($command->companyId, $plan->equipoId(), $branchScope);
        if ($equipment === null || ! $equipment->active) {
            throw new DomainException('El equipo de la asignación no existe o está inactivo.');
        }

        if (! $command->actor->canAccessBranch($command->companyId, $equipment->branchId)) {
            throw new DomainException('La sucursal del equipo no está autorizada.');
        }

        // La definición del Servicio ya viene hidratada en el plan desde tipos_servicio.
        // Una asignación sólo puede cambiar datos propios del equipo: última realización/base
        // y observaciones. Frecuencia, anticipación y prioridad no se aceptan como overrides.
        if ($plan->usaKilometraje() && ! $equipment->tracksKilometres) {
            throw new DomainException('El Servicio requiere kilometraje y el equipo no lo controla.');
        }
        if ($plan->usaHorometro() && ! $equipment->tracksHours) {
            throw new DomainException('El Servicio requiere horómetro y el equipo no lo controla.');
        }

        $updated = PlanMantenimiento::reconfigurar(
            (int) $plan->id(),
            $plan->empresaId(),
            $plan->equipoId(),
            $plan->tipoServicioId(),
            $plan->intervaloKm(),
            $plan->intervaloHorasDecimas(),
            $plan->intervaloDias(),
            $plan->anticipacionKm(),
            $plan->anticipacionHorasDecimas(),
            $plan->anticipacionDias(),
            $plan->usaKilometraje() ? $command->baseKm : null,
            $plan->usaHorometro() ? $command->baseHoursTenths : null,
            $plan->usaFecha() ? $command->baseDate : null,
            $plan->prioridad(),
            $command->notes,
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
