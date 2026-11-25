<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

interface NeumaticsUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
