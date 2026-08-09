<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class StoredAttachmentFile
{
    public function __construct(
        public string $storedName,
        public string $privateRelativePath,
    ) {
    }
}
