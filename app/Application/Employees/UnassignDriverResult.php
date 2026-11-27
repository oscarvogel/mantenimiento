<?php

declare(strict_types=1);

namespace App\Application\Employees;

use DateTimeImmutable;

final readonly class UnassignDriverResult
{
    public function __construct(
        public bool $alreadyUnassigned,
        public int $equipmentId,
        public ?int $assignmentId,
        public ?int $employeeId,
        public ?string $employeeName,
        public string $effectiveDate,
        public int $cancelledNotifications,
        public string $reason,
    ) {
    }

    public static function alreadyUnassigned(int $equipmentId, DateTimeImmutable $effectiveDate, string $reason): self
    {
        return new self(true, $equipmentId, null, null, null, $effectiveDate->format('Y-m-d'), 0, $reason);
    }

    public static function closed(ClosedDriverAssignment $closed, int $cancelledNotifications): self
    {
        return new self(
            false,
            $closed->equipmentId,
            $closed->assignmentId,
            $closed->employeeId,
            $closed->employeeName,
            $closed->endsAt->format('Y-m-d'),
            $cancelledNotifications,
            $closed->reason,
        );
    }
}
