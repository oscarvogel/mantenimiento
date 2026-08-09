<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class DownloadEquipmentAttachmentQuery
{
    public function __construct(
        public int $equipmentId,
        public int $attachmentId,
    ) {
    }
}
