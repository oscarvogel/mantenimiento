<?php

declare(strict_types=1);

namespace App\Application\Measurement\Port;

use App\Application\Measurement\ReadingHistoryPage;

interface ReadingHistoryPort
{
    /**
     * A null branch list means every branch in the tenant; an empty list means none.
     *
     * @param list<int>|null $authorizedBranchIds
     */
    public function forEquipment(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
        int $page,
        int $perPage,
    ): ReadingHistoryPage;
}
