<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DateTimeImmutable;
use DomainException;

final class WorkOrderTask
{
    private const GENERIC_RESULTS = ['ok', 'listo', 'hecho', 'reparado'];

    private function __construct(
        private readonly ?int $id,
        private readonly ?int $catalogTaskId,
        private readonly string $requestedDescription,
        private readonly bool $required,
        private readonly int $sequence,
        private string $status,
        private ?string $workPerformed,
        private ?int $responsibleUserId,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $completedAt,
        private ?string $observations,
    ) {
        if (trim($requestedDescription) === '' || $sequence < 1) {
            throw new DomainException('La tarea de la OT debe tener descripciÃ³n y orden vÃ¡lidos.');
        }
    }

    public static function snapshot(
        ?int $catalogTaskId,
        string $requestedDescription,
        bool $required,
        int $sequence,
    ): self {
        return new self(
            null,
            $catalogTaskId,
            trim($requestedDescription),
            $required,
            $sequence,
            'PENDIENTE',
            null,
            null,
            null,
            null,
            null,
        );
    }

    public static function reconstitute(
        int $id,
        ?int $catalogTaskId,
        string $requestedDescription,
        bool $required,
        int $sequence,
        string $status,
        ?string $workPerformed,
        ?int $responsibleUserId,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt,
        ?string $observations,
    ): self {
        return new self(
            $id,
            $catalogTaskId,
            $requestedDescription,
            $required,
            $sequence,
            $status,
            $workPerformed,
            $responsibleUserId,
            $startedAt,
            $completedAt,
            $observations,
        );
    }

    public function complete(string $workPerformed, DateTimeImmutable $completedAt, int $responsibleUserId): void
    {
        $workPerformed = trim($workPerformed);
        if (mb_strlen($workPerformed) < 5 || in_array(mb_strtolower($workPerformed), self::GENERIC_RESULTS, true)) {
            throw new DomainException('El trabajo realizado debe describir concretamente la intervenciÃ³n.');
        }

        $this->workPerformed = $workPerformed;
        $this->responsibleUserId = $responsibleUserId;
        $this->startedAt ??= $completedAt;
        $this->completedAt = $completedAt;
        $this->status = 'COMPLETADA';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETADA' && $this->workPerformed !== null;
    }

    public function id(): ?int { return $this->id; }
    public function catalogTaskId(): ?int { return $this->catalogTaskId; }
    public function requestedDescription(): string { return $this->requestedDescription; }
    public function required(): bool { return $this->required; }
    public function sequence(): int { return $this->sequence; }
    public function status(): string { return $this->status; }
    public function workPerformed(): ?string { return $this->workPerformed; }
    public function responsibleUserId(): ?int { return $this->responsibleUserId; }
    public function startedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function completedAt(): ?DateTimeImmutable { return $this->completedAt; }
    public function observations(): ?string { return $this->observations; }
}
