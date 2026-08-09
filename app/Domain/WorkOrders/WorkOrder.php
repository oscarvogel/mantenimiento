<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DateTimeImmutable;
use DomainException;

final class WorkOrder
{
    /** @var list<WorkOrderStateChange> */
    private array $stateChanges = [];

    /**
     * @param list<WorkOrderTask> $tasks
     */
    private function __construct(
        private readonly ?int $id,
        private readonly WorkOrderNumber $number,
        private readonly int $companyId,
        private readonly int $branchId,
        private readonly int $equipmentId,
        private readonly string $origin,
        private readonly ?int $planId,
        private readonly ?int $preventiveNoticeId,
        private readonly ?int $serviceTypeId,
        private readonly string $priority,
        private readonly ?int $responsibleUserId,
        private readonly DateTimeImmutable $openedAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $completedAt,
        private ?int $inputKilometres,
        private ?string $inputHours,
        private ?int $outputKilometres,
        private ?string $outputHours,
        private WorkOrderStatus $status,
        private ?string $waitingReason,
        private ?string $cancellationReason,
        private array $tasks,
    ) {
    }

    /**
     * @param list<WorkOrderTask> $tasks
     */
    public static function createPreventive(
        WorkOrderNumber $number,
        int $companyId,
        int $branchId,
        int $equipmentId,
        int $planId,
        int $preventiveNoticeId,
        int $serviceTypeId,
        string $priority,
        int $responsibleUserId,
        DateTimeImmutable $openedAt,
        ?int $inputKilometres,
        ?string $inputHours,
        array $tasks,
        int $actorUserId,
    ): self {
        foreach ([$companyId, $branchId, $equipmentId, $planId, $preventiveNoticeId, $serviceTypeId, $responsibleUserId, $actorUserId] as $id) {
            if ($id <= 0) {
                throw new DomainException('Las referencias de la OT preventiva deben ser vÃ¡lidas.');
            }
        }
        if ($tasks === []) {
            throw new DomainException('La OT preventiva debe contener al menos una tarea.');
        }

        self::assertUsageValue($inputKilometres, $inputHours, 'ingreso');
        $priority = mb_strtoupper(trim($priority));
        if (! in_array($priority, ['BAJA', 'MEDIA', 'ALTA', 'CRITICA'], true)) {
            throw new DomainException('La prioridad de la OT no es vÃ¡lida.');
        }

        $order = new self(
            null,
            $number,
            $companyId,
            $branchId,
            $equipmentId,
            'PREVENTIVO_VENCIDO',
            $planId,
            $preventiveNoticeId,
            $serviceTypeId,
            $priority,
            $responsibleUserId,
            $openedAt,
            null,
            null,
            $inputKilometres,
            self::normalizeHours($inputHours),
            null,
            null,
            WorkOrderStatus::DRAFT,
            null,
            null,
            $tasks,
        );
        $order->stateChanges[] = new WorkOrderStateChange(null, WorkOrderStatus::DRAFT, $openedAt, $actorUserId, 'OT preventiva creada');

        return $order;
    }

    /**
     * @param list<WorkOrderTask> $tasks
     */
    public static function reconstitute(
        int $id,
        WorkOrderNumber $number,
        int $companyId,
        int $branchId,
        int $equipmentId,
        string $origin,
        ?int $planId,
        ?int $preventiveNoticeId,
        ?int $serviceTypeId,
        string $priority,
        ?int $responsibleUserId,
        DateTimeImmutable $openedAt,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt,
        ?int $inputKilometres,
        ?string $inputHours,
        ?int $outputKilometres,
        ?string $outputHours,
        WorkOrderStatus $status,
        ?string $waitingReason,
        ?string $cancellationReason,
        array $tasks,
    ): self {
        return new self(
            $id,
            $number,
            $companyId,
            $branchId,
            $equipmentId,
            $origin,
            $planId,
            $preventiveNoticeId,
            $serviceTypeId,
            $priority,
            $responsibleUserId,
            $openedAt,
            $startedAt,
            $completedAt,
            $inputKilometres,
            self::normalizeHours($inputHours),
            $outputKilometres,
            self::normalizeHours($outputHours),
            $status,
            $waitingReason,
            $cancellationReason,
            $tasks,
        );
    }

    public function authorize(DateTimeImmutable $occurredAt, int $actorUserId): void
    {
        $this->transitionTo(WorkOrderStatus::ISSUED, $occurredAt, $actorUserId, 'OT autorizada');
    }

    public function start(DateTimeImmutable $occurredAt, int $actorUserId, ?int $inputKilometres, ?string $inputHours): void
    {
        self::assertUsageValue($inputKilometres, $inputHours, 'ingreso');
        if ($inputKilometres !== null) {
            $this->inputKilometres = $inputKilometres;
        }
        if ($inputHours !== null) {
            $this->inputHours = self::normalizeHours($inputHours);
        }
        $this->startedAt = $occurredAt;
        $this->transitionTo(WorkOrderStatus::IN_PROGRESS, $occurredAt, $actorUserId, 'OT iniciada');
    }

    public function putOnHold(string $reason, DateTimeImmutable $occurredAt, int $actorUserId): void
    {
        $reason = self::requiredReason($reason);
        $this->waitingReason = $reason;
        $this->transitionTo(WorkOrderStatus::WAITING_FOR_PARTS, $occurredAt, $actorUserId, $reason);
    }

    public function resume(DateTimeImmutable $occurredAt, int $actorUserId): void
    {
        $this->waitingReason = null;
        $this->transitionTo(WorkOrderStatus::IN_PROGRESS, $occurredAt, $actorUserId, 'OT reanudada');
    }

    public function completeTask(int $workOrderTaskId, string $workPerformed, DateTimeImmutable $completedAt, int $actorUserId): void
    {
        if ($this->status !== WorkOrderStatus::IN_PROGRESS) {
            throw new DomainException('Solo se pueden completar tareas de una OT en proceso.');
        }

        foreach ($this->tasks as $task) {
            if ($task->id() === $workOrderTaskId) {
                $task->complete($workPerformed, $completedAt, $actorUserId);

                return;
            }
        }

        throw new DomainException('La tarea no pertenece a la OT.');
    }

    public function close(WorkOrderClosure $closure, int $actorUserId): void
    {
        if ($this->status !== WorkOrderStatus::IN_PROGRESS) {
            throw new DomainException('Solo una OT en proceso puede finalizarse.');
        }
        if ($this->startedAt !== null && $closure->completedAt() < $this->startedAt) {
            throw new DomainException('La fecha de finalizaciÃ³n no puede ser anterior al inicio.');
        }

        $completedTasks = array_filter($this->tasks, static fn (WorkOrderTask $task): bool => $task->isCompleted());
        if ($completedTasks === []) {
            throw new DomainException('La OT requiere al menos un trabajo realizado para finalizar.');
        }
        foreach ($this->tasks as $task) {
            if ($task->required() && ! $task->isCompleted()) {
                throw new DomainException('Todas las tareas obligatorias deben completarse antes del cierre.');
            }
        }

        if ($this->inputKilometres !== null && $closure->outputKilometres() === null) {
            throw new DomainException('El kilometraje de salida es obligatorio para esta OT.');
        }
        if ($this->inputHours !== null && $closure->outputHours() === null) {
            throw new DomainException('El horÃ³metro de salida es obligatorio para esta OT.');
        }
        if ($closure->outputKilometres() !== null && $this->inputKilometres !== null && $closure->outputKilometres() < $this->inputKilometres) {
            throw new DomainException('El kilometraje de salida no puede ser inferior al de ingreso.');
        }
        if ($closure->outputHours() !== null && $this->inputHours !== null && (float) $closure->outputHours() < (float) $this->inputHours) {
            throw new DomainException('El horÃ³metro de salida no puede ser inferior al de ingreso.');
        }

        $this->completedAt = $closure->completedAt();
        $this->outputKilometres = $closure->outputKilometres();
        $this->outputHours = $closure->outputHours();
        $this->transitionTo(WorkOrderStatus::COMPLETED, $closure->completedAt(), $actorUserId, 'OT finalizada');
    }

    public function cancel(string $reason, DateTimeImmutable $occurredAt, int $actorUserId): void
    {
        $reason = self::requiredReason($reason);
        $this->cancellationReason = $reason;
        $this->transitionTo(WorkOrderStatus::CANCELLED, $occurredAt, $actorUserId, $reason);
    }

    public function reopen(string $reason, DateTimeImmutable $occurredAt, int $actorUserId): void
    {
        $reason = self::requiredReason($reason);
        $this->completedAt = null;
        $this->outputKilometres = null;
        $this->outputHours = null;
        $this->transitionTo(WorkOrderStatus::IN_PROGRESS, $occurredAt, $actorUserId, $reason);
    }

    private function transitionTo(WorkOrderStatus $next, DateTimeImmutable $occurredAt, int $actorUserId, ?string $comment): void
    {
        if ($actorUserId <= 0 || ! $this->status->canTransitionTo($next)) {
            throw new DomainException(sprintf('La OT no puede pasar de %s a %s.', $this->status->value, $next->value));
        }

        $previous = $this->status;
        $this->status = $next;
        $this->stateChanges[] = new WorkOrderStateChange($previous, $next, $occurredAt, $actorUserId, $comment);
    }

    private static function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new DomainException('El motivo debe tener entre 5 y 255 caracteres.');
        }

        return $reason;
    }

    private static function assertUsageValue(?int $kilometres, ?string $hours, string $label): void
    {
        if ($kilometres !== null && $kilometres < 0) {
            throw new DomainException(sprintf('El kilometraje de %s no puede ser negativo.', $label));
        }
        if ($hours !== null && (! is_numeric($hours) || (float) $hours < 0)) {
            throw new DomainException(sprintf('El horÃ³metro de %s no es vÃ¡lido.', $label));
        }
    }

    private static function normalizeHours(?string $hours): ?string
    {
        return $hours === null ? null : number_format((float) $hours, 1, '.', '');
    }

    /** @return list<WorkOrderStateChange> */
    public function releaseStateChanges(): array
    {
        $changes = $this->stateChanges;
        $this->stateChanges = [];

        return $changes;
    }

    public function id(): ?int { return $this->id; }
    public function number(): WorkOrderNumber { return $this->number; }
    public function companyId(): int { return $this->companyId; }
    public function branchId(): int { return $this->branchId; }
    public function equipmentId(): int { return $this->equipmentId; }
    public function origin(): string { return $this->origin; }
    public function planId(): ?int { return $this->planId; }
    public function preventiveNoticeId(): ?int { return $this->preventiveNoticeId; }
    public function serviceTypeId(): ?int { return $this->serviceTypeId; }
    public function priority(): string { return $this->priority; }
    public function responsibleUserId(): ?int { return $this->responsibleUserId; }
    public function openedAt(): DateTimeImmutable { return $this->openedAt; }
    public function startedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
    public function inputKilometres(): ?int { return $this->inputKilometres; }
    public function inputHours(): ?string { return $this->inputHours; }
    public function outputKilometres(): ?int { return $this->outputKilometres; }
    public function outputHours(): ?string { return $this->outputHours; }
    public function status(): WorkOrderStatus { return $this->status; }
    public function waitingReason(): ?string { return $this->waitingReason; }
    public function cancellationReason(): ?string { return $this->cancellationReason; }

    /** @return list<WorkOrderTask> */
    public function tasks(): array { return $this->tasks; }
}
