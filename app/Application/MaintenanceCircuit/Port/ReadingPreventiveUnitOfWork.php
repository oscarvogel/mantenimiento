<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit\Port;

interface ReadingPreventiveUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
