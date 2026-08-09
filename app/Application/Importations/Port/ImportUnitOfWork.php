<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

interface ImportUnitOfWork
{
    /** @template T @param callable():T $operation @return T */
    public function transactional(callable $operation): mixed;
}
