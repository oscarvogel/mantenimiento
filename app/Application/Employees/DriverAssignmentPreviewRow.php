<?php

declare(strict_types=1);

namespace App\Application\Employees;

final readonly class DriverAssignmentPreviewRow
{
    /** @param list<int> $employeeCandidateIds */
    public function __construct(
        public DriverAssignmentImportRow $source,
        public string $status,
        public ?int $equipmentId,
        public ?int $employeeId,
        public array $employeeCandidateIds,
        public string $action,
        public ?string $message = null,
    ) {}
}
