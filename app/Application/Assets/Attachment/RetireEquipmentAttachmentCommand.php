<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class RetireEquipmentAttachmentCommand
{
    public function __construct(
        public int $equipmentId,
        public int $attachmentId,
        public string $reason,
    ) {
    }
}
