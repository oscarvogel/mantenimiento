<?php

declare(strict_types=1);

namespace App\Application\Employees;

use App\Application\Audit\AuditAction;
use App\Application\Audit\AuditEntry;
use App\Application\Audit\AuditScope;
use App\Application\Audit\Port\AuditTrail;
use App\Application\Employees\Port\EmployeeAssignmentRepository;
use App\Application\Employees\Port\EmployeeUnitOfWork;
use App\Application\Employees\Port\EmployeesClock;
use App\Application\Employees\Port\PendingDriverNotifications;
use App\Application\Identity\ActorContext;
use DateTimeImmutable;
use DomainException;

/**
 * Desvincula al chofer vigente de una unidad sin dar de baja al empleado ni
 * exigir un reemplazo.
 *
 * La operación es transaccional: cierra la asignación, cancela las entregas de
 * WhatsApp que dependían de ella y registra la auditoría. Si cualquiera de las
 * tres falla, no queda nada a medias.
 *
 * Es idempotente por unidad: si el equipo ya no tiene chofer vigente, devuelve
 * un resultado informativo sin escribir auditoría ni tocar el historial.
 */
final readonly class UnassignDriver
{
    public function __construct(
        private EmployeeAssignmentRepository $assignments,
        private EmployeeUnitOfWork $unitOfWork,
        private PendingDriverNotifications $notifications,
        private AuditTrail $audit,
        private EmployeesClock $clock,
    ) {
    }

    public function execute(
        ActorContext $actor,
        int $equipmentId,
        DateTimeImmutable $effectiveDate,
        string $reason,
        ?string $notes = null,
    ): UnassignDriverResult {
        $companyId = $this->companyId($actor);
        if ($equipmentId <= 0) {
            throw new DomainException('No se indicó un equipo válido para desvincular al chofer.');
        }

        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Indicá el motivo de la desvinculación (al menos 5 caracteres).');
        }
        if (mb_strlen($reason) > 255) {
            throw new DomainException('El motivo admite hasta 255 caracteres.');
        }

        $notes = $notes === null ? null : trim($notes);
        if ($notes === '') {
            $notes = null;
        }
        if ($notes !== null && mb_strlen($notes) > 1000) {
            throw new DomainException('Las observaciones admiten hasta 1000 caracteres.');
        }

        $today = $this->clock->today()->setTime(0, 0);
        $effectiveDate = $effectiveDate->setTime(0, 0);
        if ($effectiveDate > $today) {
            throw new DomainException('La fecha efectiva no puede ser futura. Registrá la desvinculación el día en que se hace efectiva.');
        }

        return $this->unitOfWork->transactional(function () use (
            $actor,
            $companyId,
            $equipmentId,
            $effectiveDate,
            $reason,
            $notes,
        ): UnassignDriverResult {
            $closed = $this->assignments->closeCurrentForEquipment(
                $companyId,
                $equipmentId,
                $effectiveDate,
                $reason,
                $notes,
                $actor->userId(),
            );

            if ($closed === null) {
                return UnassignDriverResult::alreadyUnassigned($equipmentId, $effectiveDate, $reason);
            }

            $cancelled = $this->notifications->cancelForAssignment(
                $companyId,
                $equipmentId,
                $closed->employeeId,
                $closed->assignmentId,
                $reason,
            );

            $this->audit->record(new AuditEntry(
                scope: AuditScope::EMPRESA,
                companyId: $companyId,
                branchId: null,
                action: AuditAction::ASIGNACION_CHOFER_CERRADA,
                entity: 'employee_equipment_assignments',
                entityId: (string) $closed->assignmentId,
                actorUserId: $actor->userId(),
                actorCompanyId: $actor->companyId(),
                before: [
                    'asignacion_id' => $closed->assignmentId,
                    'empresa_id' => $closed->companyId,
                    'equipo_id' => $closed->equipmentId,
                    'equipo_codigo' => $closed->equipmentCode,
                    'equipo_patente' => $closed->equipmentPlate,
                    'sucursal_id' => $closed->branchId,
                    'empleado_id' => $closed->employeeId,
                    'empleado' => $closed->employeeName,
                    'rol' => 'CHOFER',
                    'fecha_desde' => $closed->startsAt->format('Y-m-d'),
                    'fecha_hasta' => null,
                    'observaciones' => $closed->previousNotes,
                    'motivo_cierre' => null,
                    'observaciones_cierre' => null,
                ],
                after: [
                    'asignacion_id' => $closed->assignmentId,
                    'empresa_id' => $closed->companyId,
                    'equipo_id' => $closed->equipmentId,
                    'equipo_codigo' => $closed->equipmentCode,
                    'equipo_patente' => $closed->equipmentPlate,
                    'sucursal_id' => $closed->branchId,
                    'empleado_id' => $closed->employeeId,
                    'empleado' => $closed->employeeName,
                    'rol' => 'CHOFER',
                    'fecha_desde' => $closed->startsAt->format('Y-m-d'),
                    'fecha_hasta' => $closed->endsAt->format('Y-m-d'),
                    'observaciones' => $closed->previousNotes,
                    'motivo_cierre' => $closed->reason,
                    'observaciones_cierre' => $closed->notes,
                    'notificaciones_canceladas' => $cancelled,
                ],
                reason: $reason,
            ));

            return UnassignDriverResult::closed($closed, $cancelled);
        });
    }

    private function companyId(ActorContext $actor): int
    {
        if (! $actor->hasPermission('empleados.editar')) {
            throw new DomainException('No tenés permiso para desvincular choferes.');
        }

        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || $companyId <= 0) {
            throw new DomainException('La desvinculación de choferes requiere una empresa activa.');
        }

        return $companyId;
    }
}
