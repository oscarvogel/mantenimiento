<?php

declare(strict_types=1);

namespace App\Application\Employees\Port;

use DateTimeImmutable;

interface EmployeesClock
{
    public function today(): DateTimeImmutable;
}
