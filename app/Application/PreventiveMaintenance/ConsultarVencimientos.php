<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use DomainException;

final readonly class ConsultarVencimientos
{
    public function __construct(
        private PlanMantenimientoRepository $plans,
        private PreventiveAssetGateway $assets,
        private Clock $clock,
        private EvaluadorVencimiento $evaluator,
    ) {
    }

    /** @return list<array{plan: \App\Domain\PreventiveMaintenance\PlanMantenimiento, evaluation: \App\Domain\PreventiveMaintenance\EvaluacionPlan}> */
    public function execute(ActorContext $actor, int $companyId): array
    {
        if (! $actor->hasPermission('planes.ver') || ! $actor->canAccessCompany($companyId) || $actor->isSuperAdmin()) {
            throw new DomainException('No tiene permiso o alcance para consultar vencimientos.');
        }

        $actorData  = $actor->toArray();
        $branchScope = $actorData['all_company_branches'] ? null : $actorData['branch_ids'];

        return $this->evaluate($companyId, $branchScope);
    }

    /** @param list<int>|null $branchScope */
    public function executeScoped(int $companyId, ?array $branchScope = null): array
    {
        if ($companyId <= 0) {
            throw new DomainException('La empresa para evaluar vencimientos no es válida.');
        }

        return $this->evaluate($companyId, $branchScope);
    }

    /** @param list<int>|null $branchScope */
    private function evaluate(int $companyId, ?array $branchScope): array
    {
        $results     = [];

        foreach ($this->plans->listActiveScoped($companyId, $branchScope) as $plan) {
            $equipment = $this->assets->findScoped($companyId, $plan->equipoId(), $branchScope);
            if ($equipment === null || ! $equipment->active) {
                continue;
            }

            $results[] = [
                'plan'       => $plan,
                'evaluation' => $this->evaluator->evaluar($plan, $equipment->currentUsage, $this->clock->now()),
            ];
        }

        return $results;
    }
}
