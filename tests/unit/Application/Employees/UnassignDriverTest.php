<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Employees;

use App\Application\Audit\AuditAction;
use App\Application\Audit\AuditEntry;
use App\Application\Audit\Port\AuditTrail;
use App\Application\Employees\ClosedDriverAssignment;
use App\Application\Employees\Port\EmployeeAssignmentRepository;
use App\Application\Employees\Port\EmployeeUnitOfWork;
use App\Application\Employees\Port\EmployeesClock;
use App\Application\Employees\Port\PendingDriverNotifications;
use App\Application\Employees\UnassignDriver;
use App\Application\Identity\ActorContext;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class UnassignDriverTest extends TestCase
{
    public function testClosesCurrentAssignmentAndKeepsEmployeeActiveWithoutReplacement(): void
    {
        $assignments = new RecordingAssignmentRepository($this->closedAssignment());
        $notifications = new RecordingPendingDriverNotifications(3);
        $audit = new RecordingAuditTrail();
        $unitOfWork = new RecordingEmployeeUnitOfWork();
        $useCase = $this->useCase($assignments, $unitOfWork, $notifications, $audit);

        $result = $useCase->execute(
            $this->actor(),
            43,
            new DateTimeImmutable('2026-11-27'),
            'Unidad siniestrada',
            'Queda en taller',
        );

        self::assertFalse($result->alreadyUnassigned);
        self::assertSame(97, $result->assignmentId);
        self::assertSame(4, $result->employeeId);
        self::assertSame('WALTER BROEMSER', $result->employeeName);
        self::assertSame('2026-11-27', $result->effectiveDate);
        self::assertSame(3, $result->cancelledNotifications);
        self::assertSame(1, $unitOfWork->transactions);

        self::assertCount(1, $assignments->closedCalls);
        $call = $assignments->closedCalls[0];
        self::assertSame(4, $call['companyId']);
        self::assertSame(43, $call['equipmentId']);
        self::assertSame('2026-11-27', $call['endsAt']->format('Y-m-d'));
        self::assertSame('Unidad siniestrada', $call['reason']);
        self::assertSame('Queda en taller', $call['notes']);
        self::assertSame(10, $call['actorUserId']);

        self::assertCount(1, $notifications->calls);
        self::assertSame(4, $notifications->calls[0]['companyId']);
        self::assertSame(43, $notifications->calls[0]['equipmentId']);
        self::assertSame(4, $notifications->calls[0]['employeeId']);
        self::assertSame(97, $notifications->calls[0]['assignmentId']);
        self::assertStringContainsString('Unidad siniestrada', $notifications->calls[0]['reason']);

        self::assertCount(1, $audit->entries);
        $entry = $audit->entries[0];
        self::assertSame(AuditAction::ASIGNACION_CHOFER_CERRADA, $entry->action);
        self::assertSame('employee_equipment_assignments', $entry->entity);
        self::assertSame('97', $entry->entityId);
        self::assertSame(4, $entry->companyId);
        self::assertSame(10, $entry->actorUserId);
        self::assertSame('Unidad siniestrada', $entry->reason);
        self::assertNull($entry->before['fecha_hasta'] ?? null);
        self::assertSame('2026-11-27', $entry->after['fecha_hasta'] ?? null);
        self::assertSame('NEB021', $entry->after['equipo_codigo'] ?? null);
        self::assertSame('WALTER BROEMSER', $entry->after['empleado'] ?? null);
        self::assertSame('Queda en taller', $entry->after['observaciones_cierre'] ?? null);
        self::assertSame(3, $entry->after['notificaciones_canceladas'] ?? null);
    }

    public function testIsIdempotentWhenEquipmentHasNoCurrentDriver(): void
    {
        $assignments = new RecordingAssignmentRepository(null);
        $notifications = new RecordingPendingDriverNotifications(9);
        $audit = new RecordingAuditTrail();
        $useCase = $this->useCase($assignments, new RecordingEmployeeUnitOfWork(), $notifications, $audit);

        $result = $useCase->execute($this->actor(), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');

        self::assertTrue($result->alreadyUnassigned);
        self::assertNull($result->assignmentId);
        self::assertSame(0, $result->cancelledNotifications);
        self::assertSame([], $audit->entries);
        self::assertSame([], $notifications->calls);
        self::assertCount(1, $assignments->closedCalls);
    }

    public function testRejectsFutureEffectiveDate(): void
    {
        $assignments = new RecordingAssignmentRepository($this->closedAssignment());
        $useCase = $this->useCase($assignments, new RecordingEmployeeUnitOfWork(), new RecordingPendingDriverNotifications(), new RecordingAuditTrail());

        $this->expectException(DomainException::class);
        try {
            $useCase->execute($this->actor(), 43, new DateTimeImmutable('2026-11-28'), 'Unidad siniestrada');
        } finally {
            self::assertSame([], $assignments->closedCalls);
        }
    }

    public function testRejectsShortReason(): void
    {
        $useCase = $this->useCase(new RecordingAssignmentRepository($this->closedAssignment()));

        $this->expectException(DomainException::class);
        $useCase->execute($this->actor(), 43, new DateTimeImmutable('2026-11-27'), 'Fin');
    }

    public function testRejectsActorWithoutPermission(): void
    {
        $useCase = $this->useCase(new RecordingAssignmentRepository($this->closedAssignment()));

        $this->expectException(DomainException::class);
        $useCase->execute($this->actor(permissions: []), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');
    }

    public function testRejectsSuperAdminWithoutCompany(): void
    {
        $useCase = $this->useCase(new RecordingAssignmentRepository($this->closedAssignment()));

        $this->expectException(DomainException::class);
        $useCase->execute($this->superAdmin(), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');
    }

    public function testPropagatesAuditFailureSoTheTransactionRollsBack(): void
    {
        $unitOfWork = new RecordingEmployeeUnitOfWork();
        $useCase = $this->useCase(
            new RecordingAssignmentRepository($this->closedAssignment()),
            $unitOfWork,
            new RecordingPendingDriverNotifications(),
            new ThrowingAuditTrail(),
        );

        $this->expectException(RuntimeException::class);
        try {
            $useCase->execute($this->actor(), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');
        } finally {
            self::assertTrue($unitOfWork->rolledBack);
        }
    }

    private function useCase(
        ?RecordingAssignmentRepository $assignments = null,
        ?RecordingEmployeeUnitOfWork $unitOfWork = null,
        ?RecordingPendingDriverNotifications $notifications = null,
        ?AuditTrail $audit = null,
    ): UnassignDriver {
        return new UnassignDriver(
            $assignments ?? new RecordingAssignmentRepository($this->closedAssignment()),
            $unitOfWork ?? new RecordingEmployeeUnitOfWork(),
            $notifications ?? new RecordingPendingDriverNotifications(),
            $audit ?? new RecordingAuditTrail(),
            new FixedEmployeesClock(new DateTimeImmutable('2026-11-27')),
        );
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions = ['empleados.editar']): ActorContext
    {
        return new ActorContext(10, 4, false, true, ['Administrador'], $permissions, []);
    }

    private function superAdmin(): ActorContext
    {
        return new ActorContext(10, null, true, true, ['Superadministrador'], ['empleados.editar'], []);
    }

    private function closedAssignment(): ClosedDriverAssignment
    {
        return new ClosedDriverAssignment(
            97,
            4,
            43,
            4,
            'NEB021',
            'NEB021',
            7,
            'WALTER BROEMSER',
            new DateTimeImmutable('2026-10-07'),
            new DateTimeImmutable('2026-11-27'),
            null,
            'Unidad siniestrada',
            'Queda en taller',
        );
    }
}

final class RecordingAssignmentRepository implements EmployeeAssignmentRepository
{
    /** @var list<array{companyId:int,equipmentId:int,endsAt:DateTimeImmutable,reason:string,notes:?string,actorUserId:int}> */
    public array $closedCalls = [];

    public function __construct(private readonly ?ClosedDriverAssignment $result)
    {
    }

    public function closeCurrentForEquipment(
        int $companyId,
        int $equipmentId,
        DateTimeImmutable $endsAt,
        string $reason,
        ?string $notes,
        int $actorUserId,
    ): ?ClosedDriverAssignment {
        $this->closedCalls[] = compact('companyId', 'equipmentId', 'endsAt', 'reason', 'notes', 'actorUserId');

        return $this->result;
    }

    public function currentForEmployee(int $companyId, int $employeeId): ?\App\Domain\Employees\EmployeeEquipmentAssignment
    {
        return null;
    }

    public function currentDriverForEquipment(int $companyId, int $equipmentId): ?\App\Domain\Employees\EmployeeEquipmentAssignment
    {
        return null;
    }

    public function historyForEmployee(int $companyId, int $employeeId): array
    {
        return [];
    }

    public function historyForEquipment(int $companyId, int $equipmentId): array
    {
        return [];
    }

    public function assignmentHistory(int $companyId, ?int $employeeId = null, ?string $equipmentSearch = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null, ?bool $current = null): array
    {
        return [];
    }

    public function assignDriver(int $companyId, int $employeeId, int $equipmentId, DateTimeImmutable $startsAt, ?string $notes, int $actorUserId): int
    {
        return 0;
    }

    public function closeCurrentForEmployee(int $companyId, int $employeeId, DateTimeImmutable $endsAt, int $actorUserId, ?string $reason = null): void
    {
    }
}

final class RecordingEmployeeUnitOfWork implements EmployeeUnitOfWork
{
    public int $transactions = 0;

    public bool $rolledBack = false;

    public function transactional(callable $operation): mixed
    {
        $this->transactions++;
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->rolledBack = true;
            throw $exception;
        }
    }
}

final class RecordingPendingDriverNotifications implements PendingDriverNotifications
{
    /** @var list<array{companyId:int,equipmentId:int,employeeId:int,assignmentId:int,reason:string}> */
    public array $calls = [];

    public function __construct(private readonly int $cancelled = 0)
    {
    }

    public function cancelForAssignment(int $companyId, int $equipmentId, int $employeeId, int $assignmentId, string $reason): int
    {
        $this->calls[] = compact('companyId', 'equipmentId', 'employeeId', 'assignmentId', 'reason');

        return $this->cancelled;
    }
}

final class RecordingAuditTrail implements AuditTrail
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class ThrowingAuditTrail implements AuditTrail
{
    public function record(AuditEntry $entry): void
    {
        throw new RuntimeException('No se pudo registrar la auditoría.');
    }
}

final class FixedEmployeesClock implements EmployeesClock
{
    public function __construct(private readonly DateTimeImmutable $today)
    {
    }

    public function today(): DateTimeImmutable
    {
        return $this->today;
    }
}
