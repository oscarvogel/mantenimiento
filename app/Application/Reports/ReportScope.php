<?php

declare(strict_types=1);

namespace App\Application\Reports;

use DateTimeImmutable;

final readonly class ReportScope
{
    /** @param list<int>|null $branchIds */
    public function __construct(
        public int $companyId,
        public ?array $branchIds,
        public ?int $selectedBranchId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $page,
        public int $perPage,
    ) {
    }

    public function fromDateTime(): string
    {
        return $this->from->setTime(0, 0)->format('Y-m-d H:i:s');
    }

    public function untilExclusiveDateTime(): string
    {
        return $this->to->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
    }
}
