<?php

declare(strict_types=1);

namespace App\Application\Notifications;

/** Read-only assignment data used to prepare a driver phone audit notice. */
final readonly class DriverPhoneAuditAssignment
{
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public int $equipmentId,
        public string $equipmentCode,
        public ?string $plate,
    ) {
    }
}
