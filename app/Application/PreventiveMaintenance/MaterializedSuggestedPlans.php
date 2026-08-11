<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

final readonly class MaterializedSuggestedPlans
{
    /** @param list<int> $planIds @param list<int> $noticeIds */
    public function __construct(public array $planIds, public array $noticeIds)
    {
    }
}
