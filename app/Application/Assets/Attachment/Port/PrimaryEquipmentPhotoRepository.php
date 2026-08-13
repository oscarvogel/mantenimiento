<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

use App\Application\Assets\Attachment\PrimaryEquipmentPhoto;
use App\Application\Assets\Attachment\StoredAttachmentFile;
use App\Domain\Assets\EquipmentAttachment;
use DateTimeImmutable;

interface PrimaryEquipmentPhotoRepository
{
    public function replace(
        EquipmentAttachment $photo,
        ?StoredAttachmentFile $thumbnail,
        ?string $thumbnailMimeType,
        ?int $thumbnailSize,
    ): int;

    /** @param list<int>|null $authorizedBranchIds */
    public function findScoped(int $companyId, int $equipmentId, ?array $authorizedBranchIds): ?PrimaryEquipmentPhoto;

    /** @param list<int>|null $authorizedBranchIds */
    public function retire(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
        int $actorUserId,
        DateTimeImmutable $when,
        string $reason,
    ): bool;
}
