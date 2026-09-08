<?php

declare(strict_types=1);

namespace App\Application\Employees\Port;

use App\Domain\Employees\Employee;

interface EmployeeRepository
{
    public function documentExists(int $companyId, string $document, ?int $excludingId = null): bool;

    public function employeeNumberExists(int $companyId, string $employeeNumber, ?int $excludingId = null): bool;

    public function add(Employee $employee, int $actorUserId): int;

    public function findForUpdate(int $companyId, int $employeeId): ?Employee;

    public function save(Employee $employee, int $actorUserId): void;

    /** @return list<array<string,mixed>> */
    public function list(int $companyId, ?bool $active = true, ?string $search = null): array;
}
