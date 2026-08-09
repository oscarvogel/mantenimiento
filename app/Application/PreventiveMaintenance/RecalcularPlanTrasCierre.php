<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use DateTimeImmutable;
use DomainException;

final readonly class RecalcularPlanTrasCierre
{
    public function __construct(private PlanMantenimientoRepository $plans)
    {
    }

    /**
     * Participa de la transaccion iniciada por el coordinador de cierre de OT.
     *
     * @param list<int>|null $branchIds
     */
    public function execute(
        int $companyId,
        int $planId,
        ?array $branchIds,
        DateTimeImmutable $completedAt,
        ?int $outputKm,
        ?int $outputHoursTenths,
        int $actorUserId,
    ): void {
        $plan = $this->plans->findScoped($companyId, $planId, $branchIds, true);

        if ($plan === null) {
            throw new DomainException('El plan no existe o queda fuera del alcance del cierre.');
        }

        $plan->recalcularDesdeCierre($completedAt, $outputKm, $outputHoursTenths);
        $this->plans->save($plan, $actorUserId);
    }
}
