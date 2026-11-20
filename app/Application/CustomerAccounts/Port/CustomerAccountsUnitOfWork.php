<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

interface CustomerAccountsUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
