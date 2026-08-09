<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface AssetUnitOfWork
{
    public function transactional(callable $operation): mixed;
}
