<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\QuickReadingMaintenanceReadModel;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\PreventivePlanReadModel;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use DateTimeImmutable;
use DomainException;

final readonly class GetQuickReadingMaintenanceSnapshot
{
    public function __construct(
        private PreventivePlanReadModel $plans,
        private QuickReadingMaintenanceReadModel $actions,
        private EvaluadorVencimiento $evaluator,
        private Clock $clock,
    ) {
    }

    /**
     * @param list<int> $equipmentIds
     * @return array<int,array<string,mixed>>
     */
    public function execute(ActorContext $actor, array $equipmentIds): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El estado preventivo requiere una cuenta perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('planes.ver')) {
            return [];
        }

        $equipmentIds = array_values(array_unique(array_filter(array_map('intval', $equipmentIds), static fn (int $id): bool => $id > 0)));
        if ($equipmentIds === []) {
            return [];
        }

        $scope = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $actionData = $this->actions->actions($actor->companyId(), $scope, $equipmentIds);
        $wanted = array_fill_keys($equipmentIds, true);
        $now = $this->clock->now();
        $byEquipment = [];

        foreach ($this->plans->listActive($actor->companyId(), $scope) as $item) {
            $equipmentId = $item->plan->equipoId();
            if (! isset($wanted[$equipmentId])) {
                continue;
            }

            $plan = $item->plan;
            $evaluation = $this->evaluator->evaluar($plan, $item->currentUsage, $now);
            $planId = (int) $plan->id();
            $state = $evaluation->estado();
            $order = $actionData['ordersByPlan'][$planId] ?? null;
            $notice = $actionData['noticesByPlan'][$planId] ?? null;
            $remaining = $this->remaining($plan, $item->currentUsage->kilometraje(), $item->currentUsage->horasDecimas(), $now);

            $byEquipment[$equipmentId][] = [
                'planId' => $planId,
                'serviceName' => $item->serviceName,
                'state' => $state->value,
                'displayState' => $this->displayState($state),
                'priority' => $plan->prioridad(),
                'baseKm' => $plan->baseKm(),
                'baseHours' => $this->decimalHours($plan->baseHorasDecimas()),
                'baseDate' => $plan->baseFecha()?->format('Y-m-d'),
                'nextKm' => $plan->proximoKm(),
                'nextHours' => $this->decimalHours($plan->proximasHorasDecimas()),
                'nextDate' => $plan->proximaFecha()?->format('Y-m-d'),
                'remainingKm' => $remaining['km'],
                'remainingHours' => $remaining['hours'],
                'remainingDays' => $remaining['days'],
                'critical' => $this->criticalRemaining($state, $remaining),
                'missingCriteria' => $evaluation->faltantes() === [] ? [] : array_map(static fn ($criterion): string => $criterion->value, $evaluation->faltantes()),
                'noticeId' => $notice['id'] ?? null,
                'order' => $order,
            ];
        }

        $snapshot = [];
        foreach ($equipmentIds as $equipmentId) {
            $plans = $byEquipment[$equipmentId] ?? [];
            usort($plans, fn (array $left, array $right): int => $this->sortKey($left) <=> $this->sortKey($right));
            $snapshot[$equipmentId] = [
                'state' => $plans === [] ? 'SIN_PLAN' : ($plans[0]['displayState'] ?? 'PROBLEMA'),
                'primaryPlan' => $plans[0] ?? null,
                'plans' => $plans,
                'planCount' => count($plans),
            ];
        }

        return $snapshot;
    }

    /** @return array{km:int|null,hours:float|null,days:int|null} */
    private function remaining(object $plan, ?int $currentKm, ?int $currentHoursTenths, DateTimeImmutable $now): array
    {
        $nextKm = $plan->proximoKm();
        $nextHours = $plan->proximasHorasDecimas();
        $nextDate = $plan->proximaFecha();

        return [
            'km' => $nextKm === null || $currentKm === null ? null : $nextKm - $currentKm,
            'hours' => $nextHours === null || $currentHoursTenths === null ? null : ($nextHours - $currentHoursTenths) / 10,
            'days' => $nextDate === null ? null : (int) $now->setTime(0, 0)->diff($nextDate->setTime(0, 0))->format('%r%a'),
        ];
    }

    /** @param array{km:int|null,hours:float|null,days:int|null} $remaining @return array{value:int|float,unit:string}|null */
    private function criticalRemaining(EstadoPlan $state, array $remaining): ?array
    {
        $values = [];
        foreach ([['key' => 'km', 'unit' => 'km'], ['key' => 'hours', 'unit' => 'h'], ['key' => 'days', 'unit' => 'días']] as $criterion) {
            $value = $remaining[$criterion['key']];
            if ($value !== null) {
                $values[] = ['value' => $value, 'unit' => $criterion['unit']];
            }
        }
        if ($values === []) {
            return null;
        }

        if ($state === EstadoPlan::VENCIDO) {
            $overdue = array_values(array_filter($values, static fn (array $item): bool => $item['value'] <= 0));
            if ($overdue !== []) {
                usort($overdue, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);
                return $overdue[0];
            }
        }

        $positive = array_values(array_filter($values, static fn (array $item): bool => $item['value'] >= 0));
        if ($positive !== []) {
            usort($positive, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);
            return $positive[0];
        }

        usort($values, static fn (array $a, array $b): int => abs($a['value']) <=> abs($b['value']));
        return $values[0];
    }

    /** @param array<string,mixed> $plan @return array{int,float|string,int} */
    private function sortKey(array $plan): array
    {
        $rank = match ($plan['displayState']) {
            'VENCIDO' => 0,
            'PROXIMO' => 1,
            'PROBLEMA' => 2,
            'OK' => 3,
            default => 4,
        };
        $critical = $plan['critical']['value'] ?? PHP_INT_MAX;
        return [$rank, abs((float) $critical), (int) $plan['planId']];
    }

    private function displayState(EstadoPlan $state): string
    {
        return match ($state) {
            EstadoPlan::AL_DIA => 'OK',
            EstadoPlan::PROXIMO => 'PROXIMO',
            EstadoPlan::VENCIDO => 'VENCIDO',
            EstadoPlan::SIN_DATOS => 'PROBLEMA',
        };
    }

    private function decimalHours(?int $tenths): ?string
    {
        return $tenths === null ? null : number_format($tenths / 10, 1, '.', '');
    }
}
