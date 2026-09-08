<?php

declare(strict_types=1);

namespace App\Application\Employees\Port;

use App\Domain\Employees\EmployeeEquipmentAssignment;
use DateTimeImmutable;

interface EmployeeAssignmentRepository
{
    public function currentForEmployee(int $companyId, int $employeeId): ?EmployeeEquipmentAssignment;

    public function currentDriverForEquipment(int $companyId, int $equipmentId): ?EmployeeEquipmentAssignment;

    /** @return list<array<string,mixed>> */
    public function historyForEmployee(int $companyId, int $employeeId): array;

    /** @return list<array<string,mixed>> */
    public function historyForEquipment(int $companyId, int $equipmentId): array;

    /** @return list<array<string,mixed>> */
    public function assignmentHistory(
        int $companyId,
        ?int $employeeId = null,
        ?string $equipmentSearch = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?bool $current = null,
    ): array;

    public function assignDriver(
        int $companyId,
        int $employeeId,
        int $equipmentId,
        DateTimeImmutable $startsAt,
        ?string $notes,
        int $actorUserId,
    ): int;

    public function closeCurrentForEmployee(
        int $companyId,
        int $employeeId,
        DateTimeImmutable $endsAt,
        int $actorUserId,
    ): void;
}
