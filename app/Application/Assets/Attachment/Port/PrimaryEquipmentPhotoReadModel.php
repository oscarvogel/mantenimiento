<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

interface PrimaryEquipmentPhotoReadModel
{
    /** @param list<int> $equipmentIds @param list<int>|null $authorizedBranchIds
     *  @return array<int,array{attachmentId:int,equipmentId:int,hasThumbnail:bool}>
     */
    public function forEquipmentIds(int $companyId, array $equipmentIds, ?array $authorizedBranchIds): array;
}
