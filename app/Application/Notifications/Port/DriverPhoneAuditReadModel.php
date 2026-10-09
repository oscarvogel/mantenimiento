<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Application\Notifications\DriverPhoneAuditAssignment;

interface DriverPhoneAuditReadModel
{
    /** @return list<DriverPhoneAuditAssignment> */
    public function currentAssignments(): array;

    /** @return list<int> */
    public function responsibleAdminUserIds(int $companyId): array;
}
