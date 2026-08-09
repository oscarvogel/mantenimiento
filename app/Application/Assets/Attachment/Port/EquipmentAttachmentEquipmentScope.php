<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

interface EquipmentAttachmentEquipmentScope
{
    /** @param list<int>|null $authorizedBranchIds */
    public function currentBranchId(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
    ): ?int;
}
