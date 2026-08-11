<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use DomainException;

final readonly class ReevaluateEquipmentAfterReading
{
    public function __construct(
        private PlanMantenimientoRepository $plans,
        private PreventiveAssetGateway $assets,
        private Clock $clock,
        private EvaluadorVencimiento $evaluator,
        private MaterializarAvisoVencido $materialize,
    ) {
    }

    /** @return array{evaluated:int, overdue:int, notices:list<int>} */
    public function execute(ActorContext $actor, int $equipmentId): array
    {
        if ($equipmentId <= 0 || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La reevaluación requiere un equipo y actor de empresa válidos.');
        }

        $companyId = $actor->companyId();
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $equipment = $this->assets->findScoped($companyId, $equipmentId, $branchIds);
        if ($equipment === null || ! $equipment->active) {
            throw new DomainException('El equipo leído no está disponible dentro del alcance autorizado.');
        }

        $evaluated = 0;
        $overdue = 0;
        $notices = [];
        $now = $this->clock->now();
        foreach ($this->plans->listActiveScoped($companyId, $branchIds) as $plan) {
            if ($plan->equipoId() !== $equipmentId) {
                continue;
            }
            ++$evaluated;
            $evaluation = $this->evaluator->evaluar($plan, $equipment->currentUsage, $now);
            if ($evaluation->estado() !== EstadoPlan::VENCIDO) {
                continue;
            }
            ++$overdue;
            $notices[] = $this->materialize->execute($plan, $evaluation, $now, $actor->userId());
        }

        return ['evaluated' => $evaluated, 'overdue' => $overdue, 'notices' => $notices];
    }
}
