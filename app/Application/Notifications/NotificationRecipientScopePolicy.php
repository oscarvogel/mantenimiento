<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final class NotificationRecipientScopePolicy
{
    /** @param list<int> $branchIds */
    public function allows(int $eventCompanyId, ?int $eventBranchId, int $userCompanyId, bool $allCompanyBranches, array $branchIds): bool
    {
        return $eventCompanyId === $userCompanyId
            && ($eventBranchId === null || $allCompanyBranches || in_array($eventBranchId, $branchIds, true));
    }
}
