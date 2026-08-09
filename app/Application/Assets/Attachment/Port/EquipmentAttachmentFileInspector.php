<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment\Port;

use App\Application\Assets\Attachment\InspectedAttachmentFile;

interface EquipmentAttachmentFileInspector
{
    public function inspect(string $temporaryPath): InspectedAttachmentFile;
}
