<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class PrimaryEquipmentPhoto
{
    public function __construct(
        public int $attachmentId,
        public int $equipmentId,
        public string $originalName,
        public string $originalPath,
        public string $originalMimeType,
        public int $originalSize,
        public ?string $thumbnailPath,
        public ?string $thumbnailMimeType,
        public ?int $thumbnailSize,
    ) {
    }
}
