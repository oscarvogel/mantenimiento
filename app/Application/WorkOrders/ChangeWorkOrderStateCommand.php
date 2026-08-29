<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

final readonly class ChangeWorkOrderStateCommand
{
    public function __construct(
        public int $workOrderId,
        public string $action,
        public ?string $reason = null,
    ) {
    }
}
