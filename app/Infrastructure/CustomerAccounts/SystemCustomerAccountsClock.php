<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountsClock;
use DateTimeImmutable;

final class SystemCustomerAccountsClock implements CustomerAccountsClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
