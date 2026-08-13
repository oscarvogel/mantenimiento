<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class ProcessedPhotoThumbnail
{
    public function __construct(
        public string $temporaryPath,
        public string $mimeType,
        public string $extension,
        public int $size,
    ) {
    }
}
