<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

interface PreventiveUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
