<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

use App\Application\Employees\Port\EmployeesClock;
use Config\App;
use DateTimeImmutable;
use DateTimeZone;

final class SystemEmployeesClock implements EmployeesClock
{
    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone((string) config(App::class)->appTimezone));
    }
}
