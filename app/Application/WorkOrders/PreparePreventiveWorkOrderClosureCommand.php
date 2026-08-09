<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

final readonly class PreparePreventiveWorkOrderClosureCommand
{
    /** @param array<int, string> $workPerformedByTaskId */
    public function __construct(
        public int $workOrderId,
        public array $workPerformedByTaskId,
        public ?int $outputKilometres,
        public ?string $outputHours,
    ) {
    }
}
