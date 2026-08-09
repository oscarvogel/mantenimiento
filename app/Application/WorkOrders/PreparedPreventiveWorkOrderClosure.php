<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Domain\WorkOrders\WorkOrder;
use DateTimeImmutable;

final readonly class PreparedPreventiveWorkOrderClosure
{
    public function __construct(
        public WorkOrder $workOrder,
        public DateTimeImmutable $completedAt,
        public ?int $outputKilometres,
        public ?string $outputHours,
    ) {
    }
}
