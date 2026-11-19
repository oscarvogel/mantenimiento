<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Application\Identity\ActorContext;
use App\Application\Reports\Port\ReportClock;
use DateTimeImmutable;
use DomainException;

/** Valida permisos, sucursal y período comunes a todos los informes operativos. */
final class ReportScopeFactory
{
    public function __construct(private readonly ReportClock $clock)
    {
    }

    public function build(
        ActorContext $actor,
        ?string $branchId,
        ?string $from,
        ?string $to,
        int $page = 1,
        int $perPage = 10,
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
            in_array($perPage, [5, 10, 25], true) ? $perPage : 10,
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
