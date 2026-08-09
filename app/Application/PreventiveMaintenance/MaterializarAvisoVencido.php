<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EvaluacionPlan;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use DateTimeImmutable;
use Throwable;

final readonly class MaterializarAvisoVencido
{
    public function __construct(private MaintenanceNoticeRepository $notices)
    {
    }

    public function execute(
        PlanMantenimiento $plan,
        EvaluacionPlan $evaluation,
        DateTimeImmutable $detectedAt,
        ?int $actorUserId = null,
    ): int {
        $notice   = AvisoPlan::paraPlanVencido($plan, $evaluation, $detectedAt);
        $existing = $this->notices->findByCycleKey($notice->empresaId(), $notice->planId(), $notice->claveCiclo());

        if ($existing !== null) {
            return $existing->id();
        }

        try {
            return $this->notices->save($notice, $actorUserId);
        } catch (Throwable $error) {
            // A concurrent evaluator may have won the unique cycle insert.
            $existing = $this->notices->findByCycleKey($notice->empresaId(), $notice->planId(), $notice->claveCiclo());
            if ($existing !== null) {
                return $existing->id();
            }

            throw $error;
        }
    }
}
