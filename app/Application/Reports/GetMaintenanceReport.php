<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Application\Identity\ActorContext;
use App\Application\Reports\Port\MaintenanceReportReadModel;
use App\Application\Reports\Port\ReportClock;
use DateTimeImmutable;
use DomainException;

final class GetMaintenanceReport
{
    public function __construct(
        private readonly MaintenanceReportReadModel $readModel,
        private readonly ReportClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(
        ActorContext $actor,
        ?string $branchId,
        ?string $from,
        ?string $to,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $scope = $this->scope($actor, $branchId, $from, $to, $page, $perPage);
        $report = $this->readModel->fetch($scope);
        $report['filters'] = [
            'branchId' => $scope->selectedBranchId,
            'from' => $scope->from->format('Y-m-d'),
            'to' => $scope->to->format('Y-m-d'),
        ];

        return $report;
    }

    /** @return list<array<string, mixed>> */
    public function export(
        ActorContext $actor,
        ?string $branchId,
        ?string $from,
        ?string $to,
    ): array {
        return $this->readModel->exportOrders($this->scope($actor, $branchId, $from, $to, 1, 100));
    }

    private function scope(
        ActorContext $actor,
        ?string $branchId,
        ?string $from,
        ?string $to,
        int $page,
        int $perPage,
    ): ReportScope {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('Los reportes operativos requieren una cuenta perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('reportes.ver')) {
            throw new DomainException('No tenes permiso para consultar reportes.');
        }

        $today = $this->clock->today()->setTime(0, 0);
        $fromDate = $this->dateOrDefault($from, $today->modify('-29 days'), 'desde');
        $toDate = $this->dateOrDefault($to, $today, 'hasta');
        if ($fromDate > $toDate) {
            throw new DomainException('La fecha desde no puede ser posterior a la fecha hasta.');
        }

        $selectedBranchId = $this->branchId($branchId);
        if ($selectedBranchId !== null && ! $actor->canAccessBranch($actor->companyId(), $selectedBranchId)) {
            throw new DomainException('La sucursal solicitada no pertenece al alcance autorizado.');
        }

        $branchIds = $selectedBranchId !== null
            ? [$selectedBranchId]
            : ($actor->hasAllCompanyBranches() ? null : $actor->branchIds());

        return new ReportScope(
            $actor->companyId(),
            $branchIds,
            $selectedBranchId,
            $fromDate,
            $toDate,
            max(1, $page),
            min(100, max(1, $perPage)),
        );
    }

    private function dateOrDefault(?string $value, DateTimeImmutable $default, string $field): DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new DomainException("La fecha {$field} no es valida.");
        }

        return $date;
    }

    private function branchId(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (! ctype_digit($value) || (int) $value <= 0) {
            throw new DomainException('La sucursal seleccionada no es valida.');
        }

        return (int) $value;
    }
}
