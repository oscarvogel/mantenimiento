<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class InspectedAttachmentFile
{
    public function __construct(
        public string $mimeType,
        public int $size,
    ) {
    }
}
