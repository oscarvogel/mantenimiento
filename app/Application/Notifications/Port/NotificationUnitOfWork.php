<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface NotificationUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
