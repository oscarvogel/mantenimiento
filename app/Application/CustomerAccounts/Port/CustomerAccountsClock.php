<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

use DateTimeImmutable;

interface CustomerAccountsClock
{
    public function now(): DateTimeImmutable;
}
