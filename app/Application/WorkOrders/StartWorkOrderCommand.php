<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

final readonly class StartWorkOrderCommand
{
    public function __construct(
        public int $workOrderId,
        public ?int $inputKilometres,
        public ?string $inputHours,
    ) {
    }
}
