<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

use App\Domain\Assets\EquipmentAttachment;

interface EquipmentAttachmentRepository
{
    public function add(EquipmentAttachment $attachment): int;

    /** @param list<int>|null $authorizedBranchIds */
    public function findActiveScoped(
        int $companyId,
        int $equipmentId,
        int $attachmentId,
        ?array $authorizedBranchIds,
    ): ?EquipmentAttachment;

    public function saveRetirement(EquipmentAttachment $attachment): void;
}
