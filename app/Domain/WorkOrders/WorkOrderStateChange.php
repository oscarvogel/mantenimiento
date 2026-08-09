<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DateTimeImmutable;

final readonly class WorkOrderStateChange
{
    public function __construct(
        private ?WorkOrderStatus $previous,
        private WorkOrderStatus $next,
        private DateTimeImmutable $occurredAt,
        private int $actorUserId,
        private ?string $comment,
    ) {
    }

    public function previous(): ?WorkOrderStatus
    {
        return $this->previous;
    }

    public function next(): WorkOrderStatus
    {
        return $this->next;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }
}
