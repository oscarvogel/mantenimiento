<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Application\PreventiveMaintenance\Port\PreventiveTemplateGateway;
use App\Application\PreventiveMaintenance\Port\PreventiveUnitOfWork;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\PlantillaPreventiva;
use App\Domain\PreventiveMaintenance\ResolverPlantillasCompatibles;
use DateTimeImmutable;
use DomainException;

final readonly class MaterializeSuggestedPlans
{
    public function __construct(
        private PreventiveTemplateGateway $templates,
        private PreventiveAssetGateway $assets,
        private PlanMantenimientoRepository $plans,
        private ResolverPlantillasCompatibles $resolver,
        private EvaluadorVencimiento $evaluator,
        private MaterializarAvisoVencido $materializeNotice,
        private PreventiveUnitOfWork $unitOfWork,
        private Clock $clock,
    ) {
    }

    public function execute(MaterializeSuggestedPlansCommand $command): MaterializedSuggestedPlans
    {
        $actor = $command->actor;
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('planes.editar')) {
            throw new DomainException('No tiene permiso para agregar planes desde plantilla.');
        }
        if ($command->selections === []) {
            throw new DomainException('Seleccione al menos un mantenimiento sugerido.');
        }

        $scope = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $equipment = $this->assets->findScoped($actor->companyId(), $command->equipmentId, $scope);
        if ($equipment === null || ! $equipment->active || $equipment->equipmentTypeId <= 0) {
            throw new DomainException('El equipo no existe, esta inactivo o queda fuera del alcance autorizado.');
        }

        $existingServices = [];
        foreach ($this->plans->listActiveScoped($actor->companyId(), $scope) as $existing) {
            if ($existing->equipoId() === $command->equipmentId) {
                $existingServices[] = $existing->tipoServicioId();
            }
        }
        $compatible = $this->resolver->resolve(
            $this->templates->listActiveCandidates($actor->companyId()),
            $equipment->equipmentTypeId,
            $equipment->brand,
            $equipment->model,
            $existingServices,
        );
        $byItemId = [];
        foreach ($compatible as $template) {
            $byItemId[$template->itemId] = $template;
        }

        $seenItems = [];
        $prepared = [];
        $now = $this->clock->now();
        foreach ($command->selections as $selection) {
            if (isset($seenItems[$selection->templateItemId])) {
                throw new DomainException('No se puede seleccionar dos veces la misma sugerencia.');
            }
            $seenItems[$selection->templateItemId] = true;
            $template = $byItemId[$selection->templateItemId] ?? null;
            if ($template === null) {
                throw new DomainException('Una sugerencia ya no es compatible o el plan ya fue asignado.');
            }

            $this->validateHistoricalBases($template, $selection, $equipment, $now);
            $plan = PlanMantenimiento::asignar(
                $actor->companyId(),
                $command->equipmentId,
                $template->serviceTypeId,
                $template->intervalKm,
                $template->intervalHoursTenths,
                $template->intervalDays,
                $template->warningKm,
                $template->warningHoursTenths,
                $template->warningDays,
                $template->intervalKm === null ? null : $selection->baseKm,
                $template->intervalHoursTenths === null ? null : $selection->baseHoursTenths,
                $template->intervalDays === null ? null : $selection->baseDate,
                $template->priority,
                $template->notes,
                $template->templateId,
                $template->itemId,
            );
            $prepared[] = [$plan, $this->evaluator->evaluar($plan, $equipment->currentUsage, $now)];
        }

        return $this->unitOfWork->transactional(function () use ($prepared, $actor, $scope, $now): MaterializedSuggestedPlans {
            $planIds = [];
            $noticeIds = [];
            foreach ($prepared as [$plan, $evaluation]) {
                if ($this->plans->existsActive($plan->empresaId(), $plan->equipoId(), $plan->tipoServicioId(), $scope)) {
                    throw new DomainException('El equipo ya posee uno de los planes seleccionados. No se crearon duplicados.');
                }

                $planId = $this->plans->save($plan, $actor->userId());
                $planIds[] = $planId;
                if ($evaluation->estado() !== EstadoPlan::VENCIDO) {
                    continue;
                }

                $persisted = PlanMantenimiento::reconstituir(
                    $planId,
                    $plan->empresaId(),
                    $plan->equipoId(),
                    $plan->tipoServicioId(),
                    $plan->intervaloKm(),
                    $plan->intervaloHorasDecimas(),
                    $plan->intervaloDias(),
                    $plan->anticipacionKm(),
                    $plan->anticipacionHorasDecimas(),
                    $plan->anticipacionDias(),
                    $plan->baseKm(),
                    $plan->baseHorasDecimas(),
                    $plan->baseFecha(),
                    $plan->proximoKm(),
                    $plan->proximasHorasDecimas(),
                    $plan->proximaFecha(),
                    $plan->prioridad(),
                    true,
                    $plan->observaciones(),
                    $plan->origenPlantillaId(),
                    $plan->origenPlantillaItemId(),
                );
                $noticeIds[] = $this->materializeNotice->execute($persisted, $evaluation, $now, $actor->userId());
            }

            return new MaterializedSuggestedPlans($planIds, $noticeIds);
        });
    }

    private function validateHistoricalBases(
        PlantillaPreventiva $template,
        PlanTemplateSelection $selection,
        EquipmentForPlan $equipment,
        DateTimeImmutable $now,
    ): void {
        if ($template->intervalKm !== null && ! $equipment->tracksKilometres) {
            throw new DomainException('Una plantilla seleccionada requiere kilometraje que el equipo no controla.');
        }
        if ($template->intervalHoursTenths !== null && ! $equipment->tracksHours) {
            throw new DomainException('Una plantilla seleccionada requiere horometro que el equipo no controla.');
        }
        if ($selection->baseKm !== null && $equipment->currentUsage->kilometraje() !== null
            && $selection->baseKm > $equipment->currentUsage->kilometraje()) {
            throw new DomainException('La ultima realizacion en km no puede superar la lectura actual del equipo.');
        }
        if ($selection->baseHoursTenths !== null && $equipment->currentUsage->horasDecimas() !== null
            && $selection->baseHoursTenths > $equipment->currentUsage->horasDecimas()) {
            throw new DomainException('La ultima realizacion en horas no puede superar el horometro actual del equipo.');
        }
        if ($selection->baseDate !== null && $selection->baseDate->setTime(0, 0) > $now->setTime(0, 0)) {
            throw new DomainException('La fecha de ultima realizacion no puede estar en el futuro.');
        }
    }
}
