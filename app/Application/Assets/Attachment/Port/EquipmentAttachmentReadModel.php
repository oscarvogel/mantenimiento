<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

use App\Application\Assets\Attachment\EquipmentAttachmentPage;

interface EquipmentAttachmentReadModel
{
    /** @param list<int>|null $authorizedBranchIds */
    public function forEquipment(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
        int $page,
        int $perPage,
    ): EquipmentAttachmentPage;
}
