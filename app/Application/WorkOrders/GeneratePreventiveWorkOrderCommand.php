<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

final readonly class GeneratePreventiveWorkOrderCommand
{
    /**
     * @param list<array{catalog_task_id: int|null, description: string, required: bool, sequence: int}> $tasks
     */
    public function __construct(
        public int $companyId,
        public int $branchId,
        public int $equipmentId,
        public int $planId,
        public int $preventiveNoticeId,
        public int $serviceTypeId,
        public int $responsibleUserId,
        public string $priority,
        public ?int $inputKilometres,
        public ?string $inputHours,
        public array $tasks,
    ) {
    }
}
