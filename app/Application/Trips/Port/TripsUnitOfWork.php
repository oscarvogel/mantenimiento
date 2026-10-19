<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripsUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
