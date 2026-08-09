<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\Port;

interface WorkOrderTransaction
{
    /** @template T @param callable(): T $operation @return T */
    public function run(callable $operation): mixed;
}
