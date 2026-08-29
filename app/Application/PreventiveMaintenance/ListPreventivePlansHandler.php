<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\PreventivePlanReadModel;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use DomainException;

final readonly class ListPreventivePlansHandler
{
    private const DEFAULT_PER_PAGE = 10;
    private const ALLOWED_PER_PAGE = [5, 10, 25];

    public function __construct(
        private PreventivePlanReadModel $readModel,
        private EvaluadorVencimiento $evaluator,
        private Clock $clock,
    ) {
    }

    /** @param array{q?:string,branch_id?:int|null,equipment_id?:int|null,state?:string} $filters */
    public function execute(ActorContext $actor, array $filters, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): PreventivePlanPage
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('planes.ver')) {
            throw new DomainException('No tiene permiso para consultar planes preventivos.');
        }

        $scope = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        if ($branchId !== null && $branchId > 0 && ! $actor->canAccessBranch($actor->companyId(), $branchId)) {
            throw new DomainException('La sucursal solicitada queda fuera del alcance autorizado.');
        }

        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $equipmentId = max(0, (int) ($filters['equipment_id'] ?? 0));
        $state = strtoupper(trim((string) ($filters['state'] ?? '')));
        if ($state !== '' && ! in_array($state, ['AL_DIA', 'PROXIMO', 'VENCIDO', 'SIN_DATOS'], true)) {
            throw new DomainException('El estado de plan solicitado no es válido.');
        }

        $rows = [];
        $now = $this->clock->now();
        foreach ($this->readModel->listActive($actor->companyId(), $scope) as $item) {
            if ($branchId !== null && $branchId > 0 && $item->branchId !== $branchId) continue;
            if ($equipmentId > 0 && $item->plan->equipoId() !== $equipmentId) continue;
            if ($query !== '' && ! str_contains(mb_strtolower($item->equipmentCode . ' ' . ($item->equipmentPlate ?? '') . ' ' . $item->serviceName), $query)) continue;

            $evaluation = $this->evaluator->evaluar($item->plan, $item->currentUsage, $now);
            $computedState = $evaluation->estado()->value;
            if ($state !== '' && $computedState !== $state) continue;

            $plan = $item->plan;
            $rows[] = [
                'id' => (int) $plan->id(),
                'equipment_id' => $plan->equipoId(),
                'equipment_code' => $item->equipmentCode,
                'equipment_plate' => $item->equipmentPlate,
                'equipment_type_name' => $item->equipmentTypeName,
                'branch_id' => $item->branchId,
                'branch_code' => $item->branchCode,
                'branch_name' => $item->branchName,
                'service_name' => $item->serviceName,
                'state' => $computedState,
                'priority' => $plan->prioridad(),
                'interval_km' => $plan->intervaloKm(),
                'interval_hours' => $this->decimalHours($plan->intervaloHorasDecimas()),
                'interval_days' => $plan->intervaloDias(),
                'warning_km' => $plan->anticipacionKm(),
                'warning_hours' => $this->decimalHours($plan->anticipacionHorasDecimas()),
                'warning_days' => $plan->anticipacionDias(),
                'base_km' => $plan->baseKm(),
                'base_hours' => $this->decimalHours($plan->baseHorasDecimas()),
                'base_date' => $plan->baseFecha()?->format('Y-m-d'),
                'next_km' => $plan->proximoKm(),
                'next_hours' => $this->decimalHours($plan->proximasHorasDecimas()),
                'next_date' => $plan->proximaFecha()?->format('Y-m-d'),
                'current_km' => $item->currentUsage->kilometraje(),
                'current_hours' => $this->decimalHours($item->currentUsage->horasDecimas()),
                'current_date' => $now->format('Y-m-d'),
                'notes' => $plan->observaciones(),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['equipment_code'], $left['service_name']] <=> [$right['equipment_code'], $right['service_name']]);
        $page = max(1, $page);
        $perPage = in_array($perPage, self::ALLOWED_PER_PAGE, true) ? $perPage : self::DEFAULT_PER_PAGE;
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return new PreventivePlanPage(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            $page,
            $perPage,
            $total,
            $this->readModel->listActiveEquipment($actor->companyId(), $scope),
            $this->readModel->listActiveServiceTypes($actor->companyId()),
            $this->readModel->listActiveBranches($actor->companyId(), $scope),
            [],
        );
    }

    private function decimalHours(?int $tenths): ?string
    {
        return $tenths === null ? null : number_format($tenths / 10, 1, '.', '');
    }
}
