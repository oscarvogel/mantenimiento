<?php

declare(strict_types=1);

namespace App\Application\Expirations\Port;

use App\Application\Assets\Attachment\InspectedAttachmentFile;

interface ExpirationEvidenceFileInspector
{
    public function inspect(string $temporaryPath): InspectedAttachmentFile;
}
