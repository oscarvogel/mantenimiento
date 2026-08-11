<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

use App\Application\Assets\Attachment\ProcessedPhotoThumbnail;

interface PrimaryPhotoProcessor
{
    public function createThumbnail(string $sourcePath, string $mimeType): ?ProcessedPhotoThumbnail;
}
