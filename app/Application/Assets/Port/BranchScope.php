<?php

declare(strict_types=1);

namespace App\Application\Assets\Port;

interface BranchScope
{
    public function isActiveInCompany(int $companyId, int $branchId): bool;
}
