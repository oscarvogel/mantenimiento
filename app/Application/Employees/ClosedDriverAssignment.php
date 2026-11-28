<?php

declare(strict_types=1);

namespace App\Application\Employees;

use DateTimeImmutable;

/**
 * Fotografía de la asignación que acaba de cerrarse. La usa el caso de uso para
 * auditar antes/después sin volver a consultar la base.
 */
final readonly class ClosedDriverAssignment
{
    public function __construct(
        public int $assignmentId,
        public int $companyId,
        public int $equipmentId,
        public int $employeeId,
        public string $equipmentCode,
        public string $equipmentPlate,
        public ?int $branchId,
        public string $employeeName,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ?string $previousNotes,
        public string $reason,
        public ?string $notes,
    ) {
    }
}
