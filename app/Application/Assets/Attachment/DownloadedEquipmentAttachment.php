<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class DownloadedEquipmentAttachment
{
    public function __construct(
        public string $originalName,
        public string $mimeType,
        public int $size,
        public string $content,
    ) {
    }
}
